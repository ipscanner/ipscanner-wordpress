#!/bin/sh
set -e
cd "$(dirname "$0")/.."
rm -rf build
mkdir -p build/ipscanner-bot-blocker
cp -R ipscanner.php uninstall.php readme.txt LICENSE css includes templates languages build/ipscanner-bot-blocker/
cd build && zip -qr ipscanner.zip ipscanner-bot-blocker && rm -rf ipscanner-bot-blocker
echo "build/ipscanner.zip"
