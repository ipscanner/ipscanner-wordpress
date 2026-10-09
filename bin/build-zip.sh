#!/bin/sh
set -e
cd "$(dirname "$0")/.."
rm -rf build
mkdir -p build/ipscanner
cp -R ipscanner.php uninstall.php readme.txt LICENSE css includes templates languages build/ipscanner/
cd build && zip -qr ipscanner.zip ipscanner && rm -rf ipscanner
echo "build/ipscanner.zip"
