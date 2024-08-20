#!/bin/bash

set -e

ssh chipopencart << 'END'
  cd /var/www/whmcs/public_html/modules/gateways/
  if [ -d "chip-for-whmcs" ]; then
    sudo rm -Rf "chip-for-whmcs"
  fi

  if [ -d "chip" ]; then
    sudo rm -Rf "chip"
  fi

  if [ -f "chip.php" ]; then
    sudo rm "chip.php"
  fi

  cd callback

  if [ -f "chip.php" ]; then
    sudo rm "chip.php"
  fi

  if [ -f "chip_webhook.php" ]; then
    sudo rm "chip_webhook.php"
  fi

  cd ..

  sudo git clone --branch staging https://github.com/CHIPAsia/chip-for-whmcs.git
  
  sudo chown -R www-data:www-data chip-for-whmcs

  sudo cp -ru chip-for-whmcs/modules /var/www/whmcs/public_html

  sudo rm -rf chip-for-whmcs
END