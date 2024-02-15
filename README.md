# SSL-Stripping with Vagrant and Bettercap (and how to prevent it)

When you are performing web penetration tests in your daily business, you probably come across the finding “Missing HTTP Strict Transport Security (HSTS)” pretty often.
In order to demonstrate the attack and show mitigations for it, I created a small lab with vagrant. The following steps will show the setup process and how SSL stripping is used to interfere with an encrypted HTTPS connection and downgrade it to plaintext HTTP.

## Lab Setup

If you are using another VM provisioner than VirtualBox, you will need to do some adjustments to the Vagrantfile.
The lab consists of a private network (like in a public Wi-Fi) with three hosts:
* Attacker: Kali VM, which will perform a Man-in-the-Middle attack and strip the HTTPS traffic of the victim.
*  Victim: Ubuntu VM with a desktop environment to access websites via browser.
*  Webserver: Ubuntu VM with a proof of concept web application which is SSL stripped to harvest the credentials of the victim.

The following commands need to be executed to spin up the lab. Starting the necessary VMs will take some time:

```
> git clone https://gitlab.com/fschott/ssl-stripping-lab.git
> cd ssl-stripping-lab
> vagrant up
```
If everything goes well, a window for the attacker (Kali) and the victim VM (Ubuntu) comes up. The login credentials for every VM are vagrant vagrant.

## ARP Spoofing
The SSL stripping attack belongs to techniques known as man-in-the-middle attacks. This means that the attacker places himself between the victim and usually a gateway and thus routes network traffic of his victims over his computer. This can be realized by ARP spoofing, for example, if this is not prevented by the router.
Open a terminal and start bettercap on the Kali VM. Make sure to use the correct interface:

```
> sudo bettercap --iface eth2
>> net.probe on
>> net.show
```

By setting net.probe on and net.show, other hosts in the network and their MAC address can be enumerated:

![bettercap](https://github.com/0xfschott/ssl-stripping-lab/assets/17066401/5a385e9e-ce7c-44e7-a970-f7a884ddd46e)

Head over to the victim VM now and visit the page https://cash-bank.corp in Firefox. This website is hosted on the webserver VM in the local network for demonstration but it could also be located in the public internet. It is important to use Firefox, since the CA which was used to sign the SSL certificate is imported here during the setup process.
There should be now an entry in the ARP cache which matches the MAC address from the enumerated host 192.168.56.101 above.

![arp](https://github.com/0xfschott/ssl-stripping-lab/assets/17066401/bd6b6d5c-0dba-44fa-8396-54ccf8813da5)

The next step is to perform ARP spoofing. Therefore the following attributes are set in the bettercap session:

```
set arp.spoof.target 192.168.56.102
set arp.spoof.internal true
set arp.spoof.fullduplex true
arp.spoof on
net.sniff on
```
![arp_spoof](https://github.com/0xfschott/ssl-stripping-lab/assets/17066401/34ea2dc8-6cda-42fe-95e0-6135cf8c7fd1)

If you check the ARP cache on the victim VM again, the MAC address of 192.168.56.101 has now changed to the MAC address of the attacker machine. Thus, the victim is now trying to connect to the attacker machine when https://cash-bank.corp is visited.

## SSL Stripping

Since the traffic of the victim is now routed over the attacker’s PC, SSL stripping can be performed to downgrade HTTPS connections.
Web servers are often configured to redirect users to HTTPS when they visit a web application without specifying a protocol or http:// in the browser. This is one of the points where an attacker can intercept the connection and keep it unencrypted.

On the victim VM, visit http://cash-bank.corp and your browser will be instructed via the HTTP code 301 to redirect you to HTTPS.

![cash_bank](https://github.com/0xfschott/ssl-stripping-lab/assets/17066401/ac1e619a-d1fe-45f8-af9e-5d7737806bca)

To perform SSL stripping, head over to the attacker VM, set the value set http.proxy.sslstrip true and start the proxy with http.proxy on. Bettercap takes care of the forwarding of the traffic so no manual iptables rules have to be configured.
![ssl_stripping](https://github.com/0xfschott/ssl-stripping-lab/assets/17066401/f9ca01fc-9732-4d83-86be-3f2bd086983b)

When the website is now accessed via http://cash-bank.corp, the redirection is intercepted and downgraded to HTTP.

![ssl_stripping_downgraded](https://github.com/0xfschott/ssl-stripping-lab/assets/17066401/4588b340-87db-4150-92e6-e30ecba26b1b)

If the victim now enters his credentials, they are transferred in plaintext and can be read by the attacker.

![credentials](https://github.com/0xfschott/ssl-stripping-lab/assets/17066401/70c073f2-1545-496d-902c-158897a2237b)

## Mitigation
To prevent HTTPS websites from being SSL stripped, the HTTP header HTTP Strict Transport Security (HSTS) was introduced with RFC 6797.
A HSTS header can have the following directives:
```
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
```
When a website returning this HTTP header is accessed via HTTPS for the first time, the browser pushes this site to an internally stored HSTS list. From now on, the browser will not attempt to access this page (and subdomains if includeSubDomains is set) via HTTP anymore, even if a user uses the HTTP protocol (e.g. http://cash-bank.corp).
Major browsers also maintain a list of domains that are hardcoded into the browser to always use HTTPS. Once a domain is added to this list, the browser will never attempt to connect to the site using HTTP, even on the first visit. Therefore, if the preload directive is added to the header and the website is successfully registered on https://hstspreload.org, the site will be hardcoded in the next browser release. However, an inclusion in the preload list cannot easily be reverted since that requires a change to the browser’s source code and can take months or even longer to propagate to all users. Therefore, make sure that HTTPS is supported everywhere (including on all subdomains) before opting in.

So let’s go back to the lab and implement a protection against SSL stripping. Therefore, we just need to SSH into the webserver and add the HSTS header.

```
# On the host machine:
> vagrant ssh webserver
> vim /etc/apache2/sites-available/default-ssl.conf
```
Uncomment the header in line 2 in the SSL config file:
```
<VirtualHost *:443>
 Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains"
 DocumentRoot /var/www/html/cashbank

 SSLEngine on
 SSLCertificateFile /etc/ssl/certs/webserver.crt
 SSLCertificateKeyFile /etc/ssl/private/webserver.key

 ErrorLog \${APACHE_LOG_DIR}/error.log
 CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
```
Don’t forget to apply the configuration with `sudo systemctl restart apache2`.

If you are now accessing https://cash-bank.corp and afterwards http://cash-bank.corp, the downgrade attack will fail and your browser will redirect you to HTTPS immediately (you might need some page refreshes).

## Remarks
Now one might think there is no problem if the web server does not support HTTP at all and only supports HTTPS. However, this attack relies on the user’s browser and how it tries to connect to the server.
If, for example, only https://cash-bank.corp is available, the attacker can send the link http://cash-bank.corp to the victim and just return a phishing page for the http version of the page. If the request is not forwarded, it is irrelevant whether the target page supports HTTP or not.

In the end, this attack is only prevented if the browser does not try to establish an HTTP connection. This is only the case if it has already seen the HSTS directive before or if the website is in the browser’s HSTS preload list.

## References
https://hackernoon.com/man-in-the-middle-attack-using-bettercap-framework-hd783wzy
https://www.xolphin.com/support/Apache_FAQ/Apache_-_Configuring_HTTP_Strict_Transport_Security
https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Strict_Transport_Security_Cheat_Sheet.html
