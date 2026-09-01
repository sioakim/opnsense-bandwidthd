<?php

/*
 * Copyright (C) 2026 opnsense-bandwidthd contributors
 * Licensed under the Apache License, Version 2.0.
 */

namespace OPNsense\Bandwidthd;

use OPNsense\Base\BaseModel;

/**
 * BandwidthD settings model. The heavy lifting lives in the procedural data
 * layer under scripts/OPNsense/Bandwidthd/lib; this model only owns config.xml
 * shape and validation.
 */
class Bandwidthd extends BaseModel
{
    /**
     * Cross-field validation the per-field masks cannot express.
     */
    public function performValidation($validateFullModel = false)
    {
        $messages = parent::performValidation($validateFullModel);

        foreach ($this->getFlatNodes() as $key => $node) {
            if (!$validateFullModel && !$node->isFieldChanged()) {
                continue;
            }
            $parentNode = $node->getParentNode();
            $tag = $node->getInternalXMLTagName();

            /* Each extra subnet must be a real IPv4 CIDR — the field mask only
               constrains the character set. */
            if ($tag === 'subnets_extra' && (string)$node !== '') {
                foreach (preg_split('/[,;\s]+/', (string)$node, -1, PREG_SPLIT_NO_EMPTY) as $cidr) {
                    $parts = explode('/', $cidr);
                    if (
                        count($parts) !== 2 ||
                        filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ||
                        !ctype_digit($parts[1]) || (int)$parts[1] < 0 || (int)$parts[1] > 32
                    ) {
                        $messages->appendMessage(new \OPNsense\Base\Validators\Message(
                            sprintf(gettext('"%s" is not a valid IPv4 CIDR subnet.'), $cidr),
                            $key
                        ));
                        break;
                    }
                }
            }

            /* Classification rules are "CIDR=tag", one per line. */
            if ($tag === 'classify_subnet_rules' && (string)$node !== '') {
                foreach (preg_split('/\r?\n/', (string)$node) as $line) {
                    $line = trim(preg_replace('/#.*$/', '', $line));
                    if ($line === '') {
                        continue;
                    }
                    if (!preg_match('#^[0-9.]+/(3[0-2]|[12]?[0-9])\s*=\s*[a-z0-9_-]+$#', $line)) {
                        $messages->appendMessage(new \OPNsense\Base\Validators\Message(
                            sprintf(gettext('"%s" is not a valid CIDR=tag rule.'), $line),
                            $key
                        ));
                        break;
                    }
                }
            }

            /* A database connection is only meaningful once host/name/user are set. */
            if ($tag === 'db_enable' && (string)$node === '1') {
                foreach (['db_host', 'db_name', 'db_user'] as $req) {
                    if ((string)$parentNode->$req === '') {
                        $messages->appendMessage(new \OPNsense\Base\Validators\Message(
                            gettext('Host, database name and user are required to enable the history database.'),
                            $key
                        ));
                        break;
                    }
                }
            }
        }

        return $messages;
    }
}
