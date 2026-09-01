{#
 # Copyright (C) 2026 opnsense-bandwidthd contributors
 # Licensed under the Apache License, Version 2.0.
 #}

<script>
    $(document).ready(function () {
        var data_get_map = {'frm_general': "/api/bandwidthd/settings/get"};
        mapDataToFormUI(data_get_map).done(function () {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
            updateServiceControlUI('bandwidthd');
        });

        /* Per-device overrides grid. */
        $("#grid-overrides").UIBootgrid({
            search: '/api/bandwidthd/settings/searchOverride',
            get: '/api/bandwidthd/settings/getOverride/',
            set: '/api/bandwidthd/settings/setOverride/',
            add: '/api/bandwidthd/settings/addOverride/',
            del: '/api/bandwidthd/settings/delOverride/'
        });

        $("#saveAct").click(function () {
            saveFormToEndpoint("/api/bandwidthd/settings/set", 'frm_general', function () {
                $("#saveAct_progress").addClass("fa fa-spinner fa-pulse");
                ajaxCall("/api/bandwidthd/service/reconfigure", {}, function () {
                    $("#saveAct_progress").removeClass("fa fa-spinner fa-pulse");
                    updateServiceControlUI('bandwidthd');
                });
            }, true);
        });

        $("#testDbAct").click(function () {
            $("#testDbAct_progress").addClass("fa fa-spinner fa-pulse");
            ajaxCall("/api/bandwidthd/settings/testDb", {}, function (data) {
                $("#testDbAct_progress").removeClass("fa fa-spinner fa-pulse");
                $("#testDbResult").text(data['status'] || 'failed');
            });
        });
    });
</script>

<ul class="nav nav-tabs" data-tabs="tabs" id="maintabs">
    <li class="active"><a data-toggle="tab" href="#settings">{{ lang._('Settings') }}</a></li>
    <li><a data-toggle="tab" href="#overrides">{{ lang._('Device overrides') }}</a></li>
</ul>

<div class="tab-content content-box">
    <div id="settings" class="tab-pane fade in active">
        <div class="content-box-main col-xs-12">
            {{ partial('layout_partials/base_form', ['fields': generalForm, 'id': 'frm_general']) }}
        </div>
        <div class="col-md-12">
            <hr/>
            <button class="btn btn-primary" id="saveAct" type="button">
                <b>{{ lang._('Save') }}</b> <i id="saveAct_progress"></i>
            </button>
            <button class="btn" id="testDbAct" type="button">
                {{ lang._('Test database connection') }} <i id="testDbAct_progress"></i>
            </button>
            <span id="testDbResult"></span>
        </div>
    </div>

    <div id="overrides" class="tab-pane fade">
        <div class="col-md-12">
            <p>
                {{ lang._('Per-device identity, alert and quota overrides. Match on the MAC address where you can — it survives a DHCP lease change; an IP does not. These same rows are editable inline from the dashboard.') }}
            </p>
        </div>
        <table id="grid-overrides" class="table table-condensed table-hover table-striped"
               data-editDialog="dialogOverride" data-editAlert="overrideChangeMessage">
            <thead>
            <tr>
                <th data-column-id="match" data-type="string" data-identifier="true" data-width="14em">{{ lang._('Match') }}</th>
                <th data-column-id="name" data-type="string">{{ lang._('Name') }}</th>
                <th data-column-id="vendor" data-type="string">{{ lang._('Vendor') }}</th>
                <th data-column-id="tag" data-type="string" data-width="8em">{{ lang._('Tag') }}</th>
                <th data-column-id="quota_host_gb" data-type="string" data-width="8em">{{ lang._('Quota (GB)') }}</th>
                <th data-column-id="commands" data-width="7em" data-formatter="commands" data-sortable="false">{{ lang._('Commands') }}</th>
            </tr>
            </thead>
            <tbody></tbody>
            <tfoot>
            <tr>
                <td colspan="5"></td>
                <td>
                    <button data-action="add" type="button" class="btn btn-xs btn-default"><span class="fa fa-plus"></span></button>
                    <button data-action="deleteSelected" type="button" class="btn btn-xs btn-default"><span class="fa fa-trash-o"></span></button>
                </td>
            </tr>
            </tfoot>
        </table>
    </div>
</div>

{{ partial('layout_partials/base_dialog', ['fields': overrideForm, 'id': 'dialogOverride', 'label': lang._('Edit device override')]) }}
