!#/bin/bash
find /opt/www/logs/ -mtime +3 -name "*.log" -exec rm -rf {} \;