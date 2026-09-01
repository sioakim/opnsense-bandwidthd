<?php

/*
 * Copyright (C) 2026 opnsense-bandwidthd contributors
 * Licensed under the Apache License, Version 2.0.
 */

namespace OPNsense\Bandwidthd\Api;

use OPNsense\Base\ApiControllerBase;

/**
 * Read/write JSON API behind the BandwidthD dashboard.
 *
 * These are proper API endpoints, so OPNsense handles authentication (session
 * cookie for the UI, HTTP Basic API key for scripts) and ACL for us — no
 * hand-rolled API-key file is needed.
 *
 * The procedural data layer is shared verbatim with the cron scripts; it is not
 * autoloadable, so it is pulled in by path.
 */
class DataController extends ApiControllerBase
{
    private const LIB = '/usr/local/opnsense/scripts/OPNsense/Bandwidthd/lib';

    /**
     * Load the data layer once per request.
     */
    private function lib()
    {
        require_once self::LIB . '/bwd_data.inc.php';
    }

    /**
     * Window parameters shared by every series-shaped action.
     */
    private function window()
    {
        return [
            'period' => (int)$this->request->get('period', null, 1),
            'from' => (int)$this->request->get('from', null, 0),
            'to' => (int)$this->request->get('to', null, 0),
            'tags' => bwd_parse_tags((string)$this->request->get('tags', null, '')),
        ];
    }

    public function hostsAction()
    {
        $this->lib();
        $w = $this->window();
        return bwd_hosts($w['period'], $w['from'], $w['to']);
    }

    public function seriesAction()
    {
        $this->lib();
        $w = $this->window();
        return bwd_series((string)$this->request->get('ip', null, ''), $w['period'], $w['from'], $w['to'], $w['tags']);
    }

    public function percentileAction()
    {
        $this->lib();
        $w = $this->window();
        $pct = (int)$this->request->get('pct', null, 95);
        return bwd_percentile((string)$this->request->get('ip', null, ''), $w['period'], $w['from'], $w['to'], $pct, $w['tags']);
    }

    public function dailyAction()
    {
        $this->lib();
        $w = $this->window();
        return bwd_daily_breakdown((string)$this->request->get('ip', null, ''), $w['period'], $w['from'], $w['to'], $w['tags']);
    }

    public function overviewAction()
    {
        $this->lib();
        $w = $this->window();
        $topn = max(1, min(20, (int)$this->request->get('topn', null, 8)));
        return bwd_overview($w['period'], $w['from'], $w['to'], $topn, $w['tags']);
    }

    /* All custom tags in use -> [tag => count]; drives the tag editor. */
    public function tagsAction()
    {
        $this->lib();
        return ['tags' => (object)bwd_all_custom_tags()];
    }

    public function statusAction()
    {
        $this->lib();
        return [
            'enabled' => bwd_cfg_on('enabled'),
            'cdf' => bwd_cfg_on('outputcdf'),
            'have_data' => count(bwd_cdf_files(1)) > 0,
            'probe' => bwd_cfg_on('probe_enable'),
            'db' => bwd_db_enabled(),
        ];
    }

    /**
     * Current per-host override for a device (by MAC, then IP), plus the resolved
     * global defaults so the dashboard editor can show what it would inherit.
     */
    public function overrideAction()
    {
        $this->lib();
        $mac = strtolower(trim((string)$this->request->get('mac', null, '')));
        $ip = strtolower(trim((string)$this->request->get('ip', null, '')));
        $ov = bwd_host_overrides();
        $row = ($mac && isset($ov[$mac])) ? $ov[$mac] : (($ip && isset($ov[$ip])) ? $ov[$ip] : null);

        return [
            'match' => $row['match'] ?? ($mac ?: $ip),
            'matched_by' => ($mac && isset($ov[$mac])) ? 'mac' : (($ip && isset($ov[$ip])) ? 'ip' : ($mac ? 'mac' : 'ip')),
            'row' => $row,
            'tags' => implode(', ', bwd_custom_tags($mac, $ip)),
            'globals' => [
                'alerts_enable' => bwd_cfg_on('alerts_enable') ? 'on' : 'off',
                'quota_host_gb' => bwd_cfg('quota_host_gb', '0'),
                'anomaly_enable' => bwd_cfg_on('anomaly_enable') ? 'on' : 'off',
                'exfil_enable' => bwd_cfg_on('exfil_enable') ? 'on' : 'off',
                'newdevice_enable' => bwd_cfg_on('newdevice_enable') ? 'on' : 'off',
            ],
        ];
    }

    /**
     * Host table as a CSV or JSON download, honouring the active tag filter so the
     * file matches what the operator is looking at.
     */
    public function exportAction()
    {
        $this->lib();
        $w = $this->window();
        $data = bwd_hosts($w['period'], $w['from'], $w['to']);
        if ($w['tags']) {
            $data['hosts'] = array_values(array_filter(
                $data['hosts'],
                function ($h) use ($w) {
                    return bwd_host_has_tag($h, $w['tags']);
                }
            ));
        }
        $json = $this->request->get('format', null, 'csv') === 'json';
        $stamp = date('Ymd-His');

        /* Set the headers and RETURN the body. Calling $this->response->send()
           here sends the response, then the framework sends it again, throws
           "Response Already Sent", and its error handler appends
           {"errorMessage":...} to the file the browser is saving — a corrupt
           download with a 200 status. */
        $this->response->setRawHeader('Content-Type: ' . ($json ? 'application/json' : 'text/csv; charset=utf-8'));
        $this->response->setRawHeader(
            'Content-Disposition: attachment; filename="bandwidthd-' . $stamp . ($json ? '.json"' : '.csv"')
        );
        return $json ? json_encode($data) : bwd_hosts_csv($data);
    }

    /* ------------------------------------------------------------- writes --- */

    /**
     * Create/update/remove one device's override row and its custom tags.
     *
     * Identity fields and alert toggles live in the config model; the free-form
     * custom tags stay in the rollups/custom_tags.json sidecar, which is also what
     * the alerts engine and the widget read.
     */
    public function setOverrideAction()
    {
        if (!$this->request->isPost()) {
            return ['error' => 'POST required'];
        }
        $this->lib();

        $match = strtolower(trim((string)$this->request->getPost('match', null, '')));
        /* The identity must be a MAC or an IPv4 address. Without this the model
           rejects the row later (its mask allows only hex, colons and dots) while
           the tag sidecar has already been written under a key that no device
           lookup can ever match. */
        $isMac = (bool)preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $match);
        if ($match === '' || (!$isMac && !is_ipaddrv4($match))) {
            return ['error' => 'match must be a MAC address or an IPv4 address'];
        }

        $selopts = ['inherit', 'on', 'off'];
        $row = [
            'match' => $match,
            'name' => trim((string)$this->request->getPost('name', null, '')),
            'vendor' => trim((string)$this->request->getPost('vendor', null, '')),
        ];
        foreach (['alerts_enable', 'anomaly_enable', 'exfil_enable', 'newdevice_enable'] as $f) {
            $v = (string)$this->request->getPost($f, null, 'inherit');
            $row[$f] = in_array($v, $selopts, true) ? $v : 'inherit';
        }
        $row['tag'] = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$this->request->getPost('tag', null, 'auto'))) ?: 'auto';
        $q = trim((string)$this->request->getPost('quota_host_gb', null, ''));
        $row['quota_host_gb'] = ($q === '' || !is_numeric($q)) ? '' : (string)(float)$q;

        /* A row that overrides nothing is removed, so the config stays tidy. */
        $isNoop = bwd_override_is_noop($row);
        $rows = bwd_overrides_rows();
        $idx = null;
        foreach ($rows as $i => $r) {
            if (strtolower(trim($r['match'] ?? '')) === $match) {
                $idx = $i;
                break;
            }
        }
        if ($isNoop) {
            if ($idx !== null) {
                unset($rows[$idx]);
            }
        } elseif ($idx !== null) {
            $row['uuid'] = $rows[$idx]['uuid'] ?? '';
            $rows[$idx] = $row;
        } else {
            $rows[] = $row;
        }
        if (!($isNoop && $idx === null)) {
            $saved = bwd_overrides_save(array_values($rows), "BandwidthD: per-device override via dashboard ($match)");
            if ($saved !== true) {
                /* Model validation rejected the row — report it rather than
                   claiming a save that did not happen. The tag sidecar is written
                   below, so a rejected row leaves nothing behind. */
                return ['error' => (string)$saved];
            }
        }

        /* Custom tags live in the rollups sidecar, not the config model. Written
           only once the row itself is safely stored. */
        $tags = bwd_parse_tags((string)$this->request->getPost('tags', null, ''));
        $tmap = bwd_custom_tags_map();
        if ($tags !== array_values((array)($tmap[$match] ?? []))) {
            $tmap[$match] = $tags;
            bwd_custom_tags_save($tmap);
        }

        return ['ok' => true, 'removed' => $isNoop, 'row' => $isNoop ? null : $row];
    }

    /**
     * On-demand active fingerprint of one device. Opt-in, and restricted to the
     * monitored LAN scope so this cannot become an SSRF / port-scan pivot.
     */
    public function probeAction()
    {
        if (!$this->request->isPost()) {
            return ['error' => 'POST required'];
        }
        $this->lib();
        if (!bwd_cfg_on('probe_enable')) {
            return ['error' => 'active probing is disabled'];
        }
        require_once self::LIB . '/bwd_fingerprint.inc.php';
        $ip = trim((string)$this->request->getPost('ip', null, ''));
        if (!is_ipaddrv4($ip)) {
            return ['error' => 'valid ip required'];
        }
        if (!bwd_fp_target_allowed($ip)) {
            return ['error' => 'ip is outside the monitored subnets'];
        }
        $res = bwd_fp_identify_device($ip);
        $mac = bwd_macmap()[$ip] ?? '';
        return bwd_fp_store($mac, $ip, $res);
    }

    /** Rename a custom tag across every device; renaming into an existing tag merges. */
    public function renameTagAction()
    {
        if (!$this->request->isPost()) {
            return ['error' => 'POST required'];
        }
        $this->lib();
        $from = bwd_parse_tags((string)$this->request->getPost('from', null, ''))[0] ?? '';
        $to = bwd_parse_tags((string)$this->request->getPost('to', null, ''))[0] ?? '';
        if ($from === '' || $to === '') {
            return ['error' => 'from and to required'];
        }
        if ($from === $to) {
            return ['ok' => true, 'changed' => 0];
        }
        $map = bwd_custom_tags_map();
        $changed = 0;
        foreach ($map as $k => $tags) {
            $tags = (array)$tags;
            if (!in_array($from, $tags, true)) {
                continue;
            }
            $new = [];
            foreach ($tags as $t) {
                $nt = ($t === $from) ? $to : $t;
                if (!in_array($nt, $new, true)) {
                    $new[] = $nt;
                }
            }
            $map[$k] = $new;
            $changed++;
        }
        if ($changed) {
            bwd_custom_tags_save($map);
        }
        return ['ok' => true, 'changed' => $changed, 'from' => $from, 'to' => $to];
    }

    /** Remove a custom tag from every device. */
    public function deleteTagAction()
    {
        if (!$this->request->isPost()) {
            return ['error' => 'POST required'];
        }
        $this->lib();
        $tag = bwd_parse_tags((string)$this->request->getPost('tag', null, ''))[0] ?? '';
        if ($tag === '') {
            return ['error' => 'tag required'];
        }
        $map = bwd_custom_tags_map();
        $changed = 0;
        foreach ($map as $k => $tags) {
            $tags = (array)$tags;
            if (!in_array($tag, $tags, true)) {
                continue;
            }
            $map[$k] = array_values(array_diff($tags, [$tag]));
            $changed++;
        }
        if ($changed) {
            bwd_custom_tags_save($map);
        }
        return ['ok' => true, 'changed' => $changed, 'tag' => $tag];
    }
}
