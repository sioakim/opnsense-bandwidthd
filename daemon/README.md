# Prebuilt bandwidthd daemon

The compiled `bandwidthd` binary (FreeBSD 15 / amd64, ELF, links `libpcap.so.8`
and `libgd.so.6`) plus its sample config and static HTML assets, staged exactly
as they install under `/usr/local`.

**No rc script lives here.** The daemon package deliberately ships no
`etc/rc.d/bandwidthd`: the plugin owns that file, because its `start_precmd`
regenerates `bandwidthd.conf` from `config.xml` before every start. Shipping one
from both packages made `pkg add` report a file conflict.

`libgd` is not installed on a stock OPNsense box — `scripts/install.sh` pulls it
in, and the package declares it as a dependency.
