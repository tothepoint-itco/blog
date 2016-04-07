---
layout: post
title: "Setup Let's Encrypt to Secure Your Website"
permalink: /blog/setup-lets-encrypt-to-secure-your-website/
author: michael_rigart
date:   2016-04-07
header: safe.jpg
---

If you don't know what [Let's Encrypt](https://letsencrypt.org/) is, let me briefly explain.

Let's Encrypt is a free, automated and open Certificate Authority (CA) that provides an easy way to obtain and install free TLS/SSL certificates.

As mentioned on their website, the key principles behind Let's Encrypt are:

* Free: Anyone who owns a domain name can use Let’s Encrypt to obtain a trusted certificate at zero cost.
* Automatic: Software running on a web server can interact with Let’s Encrypt to painlessly obtain a certificate, securely configure it for use, and automatically take care of renewal.
* Secure: Let’s Encrypt will serve as a platform for advancing TLS security best practices, both on the CA side and by helping site operators properly secure their servers.
* Transparent: All certificates issued or revoked will be publicly recorded and available for anyone to inspect.
* Open: The automatic issuance and renewal protocol will be published as an open standard that others can adopt.
* Cooperative: Much like the underlying Internet protocols themselves, Let’s Encrypt is a joint effort to benefit the community, beyond the control of any one organization.

As of writing, Let's Encrypt is still in a public beta phase. Currently, the entire process of obtaining and installing a certificate is only fully automated on Apache web servers.

But this doesn't mean you can't already use it for other web servers like Nginx.

This post will guide you through the installation and configuration process for getting Let's Encrypt up and running on your Ubuntu (14.04) server and Nginx.

## Install The Let's Encrypt Client

Before obtaining a certificate, you will need to install the Let's Encrypt client first. The client is in charge of obtaining and renewing the SSL certificates.

Currently, the best way of doing this is by installing the client from git, by cloning [the official GitHub repository](https://github.com/letsencrypt/letsencrypt).

First make sure you have Git and Gnu BC installed on your server. So update your server's package list and install both packages:

```
sudo apt-get update
sudo apt-get install git bc
```

Once installed, you are ready to clone the Let's Encrypt client. We will clone the repository in the ```/opt``` folder.

```
sudo git clone https://github.com/letsencrypt/letsencrypt /opt/letsencrypt
```

## Obtaining a Certificate

Let's Encrypt provides different ways to obtain certificates through different plugins. Let's first start off using the standalone plugin.

The standalone plugin works by temporarily running a small web server on port 80, to which the Let's Encrypt CA can connect and validate your server's identity before issuing a certificate.
Because it needs to run its proper web server, port 80 cannot be in use during the process.

This means, if you are already running a web server, you will need to stop it temporarily.

```
sudo service nginx stop
```

Now navigate to the letsencrypt directory and run the Standalone plugin by running the following command:

```
cd /opt/letsencrypt
sudo ./letsencrypt-auto certonly --standalone
```

Let's Encrypt will start and you will be prompted for some information. When you use the client for the first time, you will first be prompted to enter an e-mail address that will be used for notices and lost key recovery.

Enter you e-mail address and continue. Then you must agree to the Let's Encrypt Subscribe Agreement.

Next, you will need to enter the domain name(s). When using multiple domains, enter them separated by a comma and/or space.

When everything went as planned, you should see an output message containing some notes about your certificate location and expiration date.

After obtaining the certificate, you will have the following PEM-encoded files:

* cert.pem: Your domain's certificate
* chain.pem: The Let's Encrypt chain certificate
* fullchain.pem: cert.pem and chain.pem combined
* privkey.pem: Your certificate's private key

The files are placed in a subdirectory in /etc/letsencrypt/archive. However, Let's Encrypt creates symbolic links to the most recent certificate files in the /etc/letsencrypt/live/<your-domain-name> directory.
This is the path you should use to refer to your certificate files when configuring your web server.

## Configure TLS/SSL on Nginx

I assume you already have Nginx installed and configured for your website. So let's start by editing the vhost file. Take the following vhost for example.
Make sure you change the domain names (michaelrigart.be) to fit your needs.

```
server {
	listen       80;
  	server_name  www.michaelrigart.be michaelrigart.be;

  	access_log      /var/log/nginx/michaelrigart.access.log;
  	error_log       /var/log/nginx/michaelrigart.error.log;

  	location / {
   		root /srv/lonegunman/www/current/public;
	}
}
```

This basic vhost contain the port it is listening on (port 80 for http), the domain names, log location and web root.

Change the configuration as following, to make communication secure of https.
First, make the server listen to port 443 and define the certificate location:

```
server {
	listen       443 ssl;
  	server_name  www.michaelrigart.be michaelrigart.be;

   ssl_certificate /etc/letsencrypt/live/michaelrigart.be/fullchain.pem;
   ssl_certificate_key /etc/letsencrypt/live/michaelrigart.be/privkey.pem;

  	access_log      /var/log/nginx/michaelrigart.access.log;
  	error_log       /var/log/nginx/michaelrigart.error.log;

  	location / {
   		root /srv/lonegunman/www/current/public;
	}
}
```

To allow only the most secure SSL protocols and ciphers, add the following lines to the same server block:

```
    ssl_protocols TLSv1 TLSv1.1 TLSv1.2;
    ssl_prefer_server_ciphers on;
    ssl_ciphers 'EECDH+AESGCM:EDH+AESGCM:AES256+EECDH:AES256+EDH';
```

Now all communication will run over SSL. But of course, you will want to redirect the HTTP (port 80) communication to HTTPS. So add the following code outside the original server block:

```
server {
   listen 80;
   server_name michaelrigart.be www.michaelrigart.be;
   return 301 https://$host$request_uri;
}
```

Save this configuration and exit. The only thing you need to do now is start Nginx and point your browser to you newly configured vhost.

```
sudo service nginx start
```

## Set Up Auto Renewal

Let's Encrypt certificates are valid for 90 days. At the time of writing, automatic renewal is not available as a feature of the client itself, but you can manually renew your certificates by using the Let's Encrypt client.

Of course, manually keeping track of the expiration date and making sure your certificates don't expire is a tedious job. A practical solution is to create a cron job that will automatically handle the renewal process for you.

To avoid the interactive process that we used earlier, we will use the Webroot plugin instead of the Standalone plugin. That's because the Webroot plugin allows your server to validate your domain without stopping the web server.

The Webroot plugin adds a hidden file to your web server's document root, which the Let's Encrypt CA can read to verify your domain.

The Webroot plugin works by placing a special file in the /.well-known directory within your document root, which can be opened (through your web server) by the Let's Encrypt service for validation.

Let's make sure the directory is accessible to Let's Encrypt for validation by adding the following entry to our vhost configuration:

```
    location ~^/.well-known {
            root /srv/lonegunman/www/current/public;
            allow all;
    }
```

Save, exit and reload Nginx again.

Now, to automate the renewal process, we will create a Let's Encrypt configuration file. We will store this configuration file under the /etc/letsencrypt/configs folder. The configs folder does not exist by default, so you will need to create it manually:

```
mkdir /etc/letsencrypt/configs
```

Let's Encrypt configuration files are written in .ini format. Create a file for your domains with the following content:

```
# This is an example of the kind of things you can do in a configuration file.
# All flags used by the client can be configured here. Run Let's Encrypt with
# "--help" to learn more about the available options.

# Use a 4096 bit RSA key instead of 2048
rsa-key-size = 4096

# Uncomment and update to register with the specified e-mail address
email = michael@netronix.be

# Uncomment and update to generate certificates for the specified
# domains.
domains = michaelrigart.be, www.michaelrigart.be

# Uncomment to use a text interface instead of ncurses
# text = True

# Uncomment to use the standalone authenticator on port 443
# authenticator = standalone
# standalone-supported-challenges = tls-sni-01

# Uncomment to use the webroot authenticator. Replace webroot-path with the
# path to the public_html / webroot folder being served by your web server.
# authenticator = webroot
webroot-path = /srv/lonegunman/www/current/public
```

In the configuration file, we specify the following parameters:

* rsa-key-size
* email
* domains
* webroot-path

Now, instead of specifying these parameters trough the command-line, we can pass the configuration file location instead.

```
cd /opt/letsencrypt
sudo ./letsencrypt-auto certonly -a webroot --renew-by-default --config /etc/letsencrypt/configs/michaelrigart.ini
```

Now every time you run this command, Let's Encrypt will renew the certificates. This is not something you'll want to do, So let's create a script that will only renew, when your certificates will expire within 30 days.

I use the following bash-script to handle the renewal process for me:

```
#!/bin/bash

web_service='nginx'
le_path='/opt/letsencrypt'
exp_limit=30;

config_files=(/etc/letsencrypt/configs/*.ini)


for config_file in "${config_files[@]}"
do

  if [ ! -f $config_file ]; then
    echo "[ERROR] config file does not exist: $config_file"
    exit 1;
  fi

  domain=`grep "^\s*domains" $config_file | sed "s/^\s*domains\s*=\s*//" | sed 's/(\s*)\|,.*$//'`
  cert_file="/etc/letsencrypt/live/$domain/fullchain.pem"

  if [ ! -f $cert_file ]; then
    echo "[ERROR] certificate file not found for domain $domain."
  fi

  exp=$(date -d "`openssl x509 -in $cert_file -text -noout|grep "Not After"|cut -c 25-`" +%s)
  datenow=$(date -d "now" +%s)
  days_exp=$(echo \( $exp - $datenow \) / 86400 |bc)

  echo "Checking expiration date for $domain..."

  if [ "$days_exp" -gt "$exp_limit" ] ; then
    echo "The certificate is up to date, no need for renewal ($days_exp days left)."
  else
    echo "The certificate for $domain is about to expire soon. Starting webroot renewal script..."
    $le_path/letsencrypt-auto certonly -a webroot --agree-tos --renew-by-default --config $config_file
    echo "Reloading $web_service"
    /usr/sbin/service $web_service reload
    echo "Renewal process finished for domain $domain"
  fi
done
```

At the top of the script, you can specify some parameters:

* web_service: name of the service you are running
* le_path: path where the Let's Encrypt agent is installed
* exp_limit: number of days Let's Encrypt needs to renew the certificates
* config_files: path to the Let's Encrypt configuration files

The script will collect all available configuration files and loop over them, one by one. It will check whether the configuration file and certificate files exist.

When they both exist, the script will check the certificate's  number of days until expiration. When the number of days is less or equal than the exp_limit parameter, it will run the renewal command and reload your web server. Otherwise it will just output the number of days before expiration.

Save the script in /etc/letsencrypt/le-renew-webroot and make it executable:

```
sudo chmod +x /etc/letsencrypt/le-renew-webroot
```

Next, we will edit the crontab to create a new job that will run this command every week. To edit the crontab for the root user, run:

```
sudo crontab -e
```

Include the following content, all in one line:

```
30 2 * * 1 /usr/local/sbin/le-renew-webroot >> /var/log/le-renewal.log
```

This will create a new cron job that will execute the le-renew-webroot command every Monday at 2:30 am. The output produced by the command will be piped to a log file located at /var/log/le-renewal.log.

## Let's Encrypt Webroot plugin

We have generated the certificates by using Let's Encrypt standalone plugin and setup the auto-renewal with the webroot plugin.

With the standalone plugin, you were obliged to stop your own webserver so the standalone server could run on port 80. It's obvious that you don't want to go down that path when you have a production site running.

To avoid downtime, use the webroot plugin to install your certificates.
As described during the auto-renewal step, we need the following two prerequisites:

1. make sure the .well-known folder is accessible
2. generate a Let's Encrypt config file for your website

Once those are set-up, you can generate the certificates with a single command without the need to stop your webserver:

```
cd /opt/letsencrypt
sudo ./letsencrypt-auto certonly -a webroot --agree-tos -c /path/to/your/config.ini
```

The above command invokes the letsencrypt binary to generate the certificates. We then specify the webroot plugin using the option ```-a webroot``` . With the ```--agree-tos``` option, we tell Let's Encrypt to automatically agree with the terms of service, so we don't get the prompt anymore.
With the last option ```-c /path/to/your/config.ini```, we tell Let's Encrypt which configuration file to use.

## Make Your Configuration More Secure

Head over to the Qualys SSL Labs website to [test your SSL configuration](https://www.ssllabs.com/ssltest/). Just enter your domain name and wait for the result.

Chances are you'll get a B grade. To get a A+ grading, open your vhost configuration and replace the underlying snippet:

```
    ssl_protocols TLSv1 TLSv1.1 TLSv1.2;
    ssl_prefer_server_ciphers on;
    ssl_ciphers 'EECDH+AESGCM:EDH+AESGCM:AES256+EECDH:AES256+EDH';
```

with this detailed one:

```
    ssl_protocols TLSv1 TLSv1.1 TLSv1.2;
    ssl_prefer_server_ciphers on;
    ssl_dhparam /etc/ssl/private/dhparams.pem;
    ssl_ciphers "EECDH+AESGCM:EDH+AESGCM:AES256+EECDH:AES256+EDH";
    ssl_ecdh_curve secp384r1; # Requires nginx >= 1.1.0
    ssl_session_cache shared:SSL:10m;
    ssl_session_tickets off; # Requires nginx >= 1.5.9
    ssl_stapling on; # Requires nginx >= 1.3.7
    ssl_stapling_verify on; # Requires nginx => 1.3.7
    resolver <insert-ip-dns-server-1> <insert-ip-dns-server-2> valid=300s;
    resolver_timeout 5s;
    add_header Strict-Transport-Security "max-age=63072000; includeSubdomains; preload";
    add_header X-Frame-Options DENY;
    add_header X-Content-Type-Options nosniff;
```

Make sure you change <insert-ip-dns-server-1> and <insert-ip-dns-server-2> to the proper IP addresses of the DNS servers you use.
I'm not going to explain all settings here. Read the great and detailed blog post about [Strong SSL Security On Nginx](https://raymii.org/s/tutorials/Strong_SSL_Security_On_nginx.html) for a more detailed explanation.

You will need to generate a unique DH Group with the openssl command. I create the file in the /etc/ssl/private/ directory. When you don't have this directory on your server, create it with these commands:

```
	sudo mkdir -p /etc/ssl/private
	sudo chmod 710 /etc/ssl/private
```

Generate the dhparams.pem file and set secure permissions:

```
	cd /etc/ssl/private
	sudo openssl dhparam -out dhparams.pem 4096
	sudo chmod 600 dhparams.pem
```

Congratulations! Reload Nginx and re-run the SSL server test to receive your +A grade.