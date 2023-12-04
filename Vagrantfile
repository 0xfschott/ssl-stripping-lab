Vagrant.configure("2") do |config|

  config.vm.network "private_network", type: "dhcp"

  config.vm.define "webserver" do |webserver|
    webserver.vm.box = "ubuntu/bionic64"
    webserver.vm.box_version = "20230607.0.0"
    webserver.vm.network "private_network", ip: "192.168.56.101"
    webserver.vm.synced_folder "cashbank", "/var/www/html/cashbank"

    webserver.vm.provision "shell", inline: <<-SHELL
    apt-get update  
    apt-get install -y apache2 php libapache2-mod-php

    # Create a CA and generate certificate
    cd /vagrant/certificates
    openssl genrsa -out CBCA.key 2048 
    openssl req -x509 -new -nodes -key CBCA.key -sha256 -days 1024 -out CBCA.pem -subj "/C=US/ST=NewYork/L=NewYork/O=CB/OU=IT/CN=cash-bank.corp"
    openssl genrsa -out webserver.key 2048 
    openssl req -new -key webserver.key -out webserver.csr -subj "/C=US/ST=NewYork/L=NewYork/O=CB/OU=IT/CN=cash-bank.corp"
    cat <<EOF > webserver.ext
      authorityKeyIdentifier=keyid,issuer
      basicConstraints=CA:FALSE
      keyUsage = digitalSignature, nonRepudiation, keyEncipherment, dataEncipherment
      subjectAltName = @alt_names
      [alt_names]
      DNS.1 = cash-bank.corp
EOF
    openssl x509 -req -in webserver.csr -CA CBCA.pem -CAkey CBCA.key -CAcreateserial -out webserver.crt -days 365 -sha256 -extfile webserver.ext

    a2enmod ssl
    a2enmod rewrite
    a2enmod headers

    chown -R www-data:www-data /var/www/html/cashbank
    chmod -R 755 /var/www/html/cashbank

    cp /vagrant/certificates/webserver.crt /etc/ssl/certs/webserver.crt
    cp /vagrant/certificates/webserver.key /etc/ssl/private/webserver.key
    # Apache Configuration for SSL
    cat <<EOF > /etc/apache2/sites-available/default-ssl.conf
    <VirtualHost *:443>
        #Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains"
        DocumentRoot /var/www/html/cashbank

        SSLEngine on
        SSLCertificateFile /etc/ssl/certs/webserver.crt
        SSLCertificateKeyFile /etc/ssl/private/webserver.key

        ErrorLog \${APACHE_LOG_DIR}/error.log
        CustomLog \${APACHE_LOG_DIR}/access.log combined
    </VirtualHost>
EOF  

    # Apache Configuration to Redirect HTTP to HTTPS
    cat <<EOF > /etc/apache2/sites-available/000-default.conf
    <VirtualHost *:80>
        DocumentRoot /var/www/html/cashbank

        RewriteEngine On
        RewriteCond %{HTTPS} !=on
        RewriteRule ^/?(.*) https://cash-bank.corp/$1 [R,L]
    </VirtualHost>
EOF

    # Enable the SSL site
    a2ensite default-ssl
    systemctl restart apache2
  SHELL
  end

  # Victim Machine Configuration
  config.vm.define "victim" do |victim|
    victim.vm.box = "ubuntu/bionic64"
    victim.vm.box_version = "20230607.0.0"
    victim.vm.network "private_network", ip: "192.168.56.102"
    victim.vm.provider "virtualbox" do |vb|
      vb.gui = true
      vb.memory = "2048"
    end
    victim.vm.provision "shell", inline: <<-SHELL
    apt-get update
    apt-get install -y libnss3-tools ubuntu-desktop xvfb
    
    # Open firefox to create profile
    Xvfb :99 &
    export DISPLAY=:99
    sudo -u vagrant -H firefox &
    sleep 10
    # Add CA to firefox
    cp /vagrant/certificates/CBCA.pem /usr/local/share/ca-certificates/CBCA.pem
    certificateFile="/usr/local/share/ca-certificates/CBCA.pem"
    certificateName="Cash Bank CA" 
    for certDB in $(find  /home/vagrant/.mozilla* -name "cert9.db")
    do
      certDir=$(dirname ${certDB});
      certutil -A -n "${certificateName}" -t "TCu,Cuw,Tuw" -i ${certificateFile} -d sql:${certDir}
      echo "Successfully added CA!"
    done
    reboot

  SHELL
  end

  # Attacker Machine Configuration
  config.vm.define "attacker" do |attacker|
    attacker.vm.box = "kalilinux/rolling"
    attacker.vm.network "private_network", ip: "192.168.56.103"
    attacker.vm.provision "shell", inline: <<-SHELL
      apt-get update
      apt install -y bettercap
    SHELL
  end

  config.vm.provision "shell", inline: <<-SHELL
  echo "192.168.56.101 cash-bank.corp" | sudo tee -a /etc/hosts
SHELL
end