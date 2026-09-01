{#
 # Copyright (C) 2026 opnsense-bandwidthd contributors
 # Licensed under the Apache License, Version 2.0.
 #
 # The dashboard is a self-contained vanilla-JS app in /bandwidthd_ui, but its
 # chrome is OPNsense's own: every panel is a .content-box,
 # laid out on the Bootstrap 3 grid the rest of the GUI uses. That is what makes it
 # inherit the active theme (light, dark or auto) without this page knowing which
 # one is active — see the note at the top of bandwidthd.css.
 #}

{# The layout only patches jQuery's ajax with the CSRF header, and this app uses
 # plain fetch(), so hand the token to the JS explicitly. #}
<script>
    window.bwdCsrfToken = "{{ csrf_token }}";
</script>

<link rel="stylesheet" href="/bandwidthd_ui/css/bandwidthd.css?v=19"/>

<div id="bwd-app" class="bwd-app">
    <div id="bwd-banner" class="bwd-banner" role="status" hidden></div>

    {# ---- controls -------------------------------------------------------- #}
    <div class="row">
        <div class="col-xs-12">
            <div class="content-box bwd-reveal">
                <div class="bwd-toolbar">
                    <div class="bwd-windows" role="group" aria-label="{{ lang._('Time window') }}">
                        <button type="button" class="bwd-window" data-secs="3600" aria-pressed="false" data-period="1">1h</button>
                        <button type="button" class="bwd-window" data-secs="21600" aria-pressed="false" data-period="1">6h</button>
                        <button type="button" class="bwd-window is-active" data-secs="86400" data-period="1" aria-pressed="true">24h</button>
                        <button type="button" class="bwd-window" data-secs="604800" aria-pressed="false" data-period="2">7d</button>
                        <button type="button" class="bwd-window" data-secs="2592000" aria-pressed="false" data-period="3">30d</button>
                        <button type="button" class="bwd-window" data-secs="31536000" aria-pressed="false" data-period="4">1y</button>
                        <button type="button" class="bwd-window bwd-window-custom" data-custom="1" aria-expanded="false" aria-controls="bwd-customrange">{{ lang._('Custom') }}</button>
                    </div>
                    <div class="bwd-totals">
                        <span class="bwd-pill bwd-in"><i></i>{{ lang._('In') }} <b id="bwd-tot-in">–</b></span>
                        <span class="bwd-pill bwd-out"><i></i>{{ lang._('Out') }} <b id="bwd-tot-out">–</b></span>
                        <span class="bwd-export" role="group" aria-label="{{ lang._('Export data') }}">
                            <button type="button" id="bwd-export-csv" class="bwd-export-btn" title="{{ lang._('Download the host table for this window as CSV (honours the tag filter; the search box is not applied)') }}">CSV</button>
                            <button type="button" id="bwd-export-json" class="bwd-export-btn" title="{{ lang._('Download the host table for this window as JSON (honours the tag filter; the search box is not applied)') }}">JSON</button>
                        </span>
                        <span class="bwd-updated" id="bwd-updated" role="status"></span>
                    </div>
                </div>
                <div class="bwd-customrange" id="bwd-customrange" hidden>
                    <span class="bwd-range-label">{{ lang._('Custom range') }}</span>
                    <label class="bwd-dt">{{ lang._('From') }} <input type="datetime-local" id="bwd-from"></label>
                    <label class="bwd-dt">{{ lang._('To') }} <input type="datetime-local" id="bwd-to"></label>
                    <button type="button" id="bwd-range-reset" class="bwd-range-reset" title="{{ lang._('Back to 24h') }}">{{ lang._('Reset') }}</button>
                </div>
            </div>
        </div>
    </div>

    {# ---- overview -------------------------------------------------------- #}
    <div class="row">
        <div class="col-xs-12">
            <div class="content-box bwd-reveal bwd-reveal-1" id="bwd-overview">
                <div class="bwd-hero">
                    <div class="bwd-hero-main">
                        <span class="bwd-hero-label">{{ lang._('Total traffic') }} · <span id="bwd-hero-window">{{ lang._('this window') }}</span></span>
                        <span class="bwd-hero-num" id="bwd-hero-total">–</span>
                    </div>
                    <div class="bwd-hero-side">
                        <div class="bwd-hero-stat bwd-in">
                            <span class="bwd-hero-stat-k">{{ lang._('Download') }}</span>
                            <span class="bwd-hero-stat-v"><i>▼</i> <span id="bwd-hero-in">–</span></span>
                        </div>
                        <div class="bwd-hero-stat bwd-out">
                            <span class="bwd-hero-stat-k">{{ lang._('Upload') }}</span>
                            <span class="bwd-hero-stat-v"><i>▲</i> <span id="bwd-hero-out">–</span></span>
                        </div>
                    </div>
                </div>
                <div class="bwd-cards" id="bwd-cards"></div>
            </div>
        </div>
    </div>

    {# ---- top talkers over time ------------------------------------------- #}
    <div class="row">
        <div class="col-xs-12">
            <div class="content-box bwd-reveal bwd-reveal-2">
                <div class="bwd-overview-head">
                    <span class="bwd-overview-title">{{ lang._('Traffic over time — top talkers') }}</span>
                    <span class="bwd-overview-hint">{{ lang._('stacked Mbps per bin') }}</span>
                </div>
                <div class="bwd-overview-canvas"><canvas id="bwd-overview-chart" role="img" aria-label="{{ lang._('Stacked traffic over time for the top talkers') }}"></canvas></div>
                <div id="bwd-overview-empty" class="bwd-empty" hidden>{{ lang._('No data for this window yet.') }}</div>
            </div>
        </div>
    </div>

    {# ---- devices + detail ------------------------------------------------ #}
    <div class="row bwd-reveal bwd-reveal-3">
        <div class="col-xs-12 col-md-4">
            <div class="content-box bwd-list-pane">
                <div class="bwd-list-head">
                    <input type="search" id="bwd-search" class="bwd-search" aria-label="{{ lang._('Search hosts') }}"
                           placeholder="{{ lang._('Search host, name, MAC, or vendor…') }}" autocomplete="off"/>
                    <div class="bwd-viewtoggle" role="group" aria-label="{{ lang._('Label by') }}">
                        <button type="button" id="bwd-view-ip" class="is-active" data-view="ip">IP</button>
                        <button type="button" id="bwd-view-name" data-view="name">{{ lang._('Name') }}</button>
                    </div>
                </div>
                <div class="bwd-sortbar">
                    <button type="button" class="bwd-sort is-active" data-sort="total">{{ lang._('Total') }}</button>
                    <button type="button" class="bwd-sort" data-sort="in">{{ lang._('In') }}</button>
                    <button type="button" class="bwd-sort" data-sort="out">{{ lang._('Out') }}</button>
                    <button type="button" class="bwd-sort" data-sort="label">{{ lang._('Name') }}</button>
                </div>
                <div id="bwd-tagbar" class="bwd-tagbar" role="group" aria-label="{{ lang._('Filter by device tag') }}" hidden></div>
                <div id="bwd-tageditor" class="bwd-tageditor" hidden></div>
                <ul id="bwd-hostlist" class="bwd-hostlist" role="listbox" aria-label="{{ lang._('Hosts') }}"></ul>
            </div>
        </div>

        <div class="col-xs-12 col-md-8">
            <div class="content-box bwd-detail-pane">
                <div class="bwd-detail-head">
                    <div>
                        <h2 id="bwd-detail-title">{{ lang._('Select a host') }}</h2>
                        <div id="bwd-detail-sub" class="bwd-detail-sub"></div>
                    </div>
                    <div class="bwd-detail-stats" id="bwd-detail-stats"></div>
                </div>
                <div class="bwd-pctile" id="bwd-pctile" hidden></div>
                <div class="bwd-chart-wrap">
                    <canvas id="bwd-chart" role="img" aria-label="{{ lang._('Traffic over time for the selected host') }}"></canvas>
                    <div id="bwd-chart-empty" class="bwd-empty" hidden>{{ lang._('No time-series data yet for this host.') }}</div>
                </div>
                <div class="bwd-proto" id="bwd-proto"></div>
                <div class="bwd-daily" id="bwd-daily" hidden></div>
                <div class="bwd-alertcfg" id="bwd-alertcfg" hidden></div>
            </div>
        </div>
    </div>
</div>

<script src="/bandwidthd_ui/vendor/chart.umd.min.js"></script>
<script src="/bandwidthd_ui/js/bandwidthd.js?v=19"></script>
