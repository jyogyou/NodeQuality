#!/bin/bash

HEADING_DATE="$(TZ='Asia/Shanghai' date +'%Y-%m-%d %H:%M:%S CST')"
echo -ne "\e[0;36m"
cat <<-EOF
########################################################################
                  bash <(curl -sL https://test.etdata.link)
                   https://github.com/jyogyou/NodeQuality
        报告时间：$HEADING_DATE  脚本版本：v0.0.1
        联系: https://t.me/hketsp_bot
        频道: https://t.me/hketdata 网站：https://www.qyt-idc.com
########################################################################
EOF
echo -ne "\033[0m"
