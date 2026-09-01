{% if helpers.exists('OPNsense.Bandwidthd.general') and OPNsense.Bandwidthd.general.enabled == '1' %}
bandwidthd_enable="YES"
{% else %}
bandwidthd_enable="NO"
{% endif %}
