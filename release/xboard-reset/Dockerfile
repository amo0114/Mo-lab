FROM ghcr.io/cedar2025/xboard@sha256:2633d6085768c57b5234b165a57569502728a795888602a7c233ccd3d4eb47b8
COPY TrafficResetService.php /www/app/Services/TrafficResetService.php
COPY FakaBridge/ /www/plugins/FakaBridge/
COPY manifest.json /www/monexus-reset-release.json
COPY verify-artifacts.php /usr/local/bin/monexus-verify-reset-release.php
RUN php /usr/local/bin/monexus-verify-reset-release.php
