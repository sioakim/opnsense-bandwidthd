# Build recipe for the opnsense/plugins ports tree.
#
# Drop this directory in as net/bandwidthd inside a checkout of
# https://github.com/opnsense/plugins and run `make package` there. That path
# needs the plugins tree's Mk/plugins.mk; to build standalone against nothing but
# pkg(8), use ../scripts/build-pkgs.sh instead.

PLUGIN_NAME=		bandwidthd
PLUGIN_VERSION=		1.0.2
PLUGIN_COMMENT=		Per-device bandwidth accounting, alerting and history
PLUGIN_MAINTAINER=	spyros@ioakeim.com
PLUGIN_WWW=		https://github.com/sioakim/opnsense-bandwidthd
PLUGIN_DEPENDS=		bandwidthd

.include "../../Mk/plugins.mk"
