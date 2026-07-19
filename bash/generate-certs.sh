#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CERT_DIR="${ROOT_DIR}/nginx/certs"
CERT_FILE="${CERT_DIR}/localhost.pem"
KEY_FILE="${CERT_DIR}/localhost-key.pem"

if ! command -v mkcert >/dev/null 2>&1; then
    echo "Error: mkcert is not installed. See README.md for installation instructions." >&2
    exit 1
fi

mkdir -p "${CERT_DIR}"

echo "Installing the local mkcert certificate authority if required..."
mkcert -install

echo "Generating certificates for Pane and Latte..."
mkcert \
    -cert-file "${CERT_FILE}" \
    -key-file "${KEY_FILE}" \
    pane.localhost latte.localhost localhost 127.0.0.1 ::1

echo "Certificate: ${CERT_FILE}"
echo "Private key: ${KEY_FILE}"
