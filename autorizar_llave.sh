#!/bin/bash
# Autoriza la llave PÚBLICA de deploy (deploy-talent360-v2) en /root/.ssh/authorized_keys.
# Idempotente: correrlo dos veces no duplica. Pensado para pegarse desde la consola web de
# Hetzner, donde escribir '>>' a mano falla por la distribución del teclado.
set -e

KEY='ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGfz/258tHE4uLlTdbvyntEgpvpO3flpTAmsYDzVLxME deploy-talent360-v2'

mkdir -p /root/.ssh
chmod 700 /root/.ssh
touch /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys

if grep -qF "deploy-talent360-v2" /root/.ssh/authorized_keys; then
    echo "La llave ya estaba autorizada. Nada que hacer."
else
    echo "$KEY" >> /root/.ssh/authorized_keys
    echo "Llave deploy-talent360-v2 AUTORIZADA."
fi
