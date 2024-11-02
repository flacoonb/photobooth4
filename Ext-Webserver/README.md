# Selfie-Upload einrichten

Diese Anleitung beschreibt, wie du das Selfie-Upload-System mit Webhooks auf einem Webserver und einer Photobooth einrichtest.

## Voraussetzungen

Damit der Webhook korrekt funktioniert, müssen folgende Voraussetzungen erfüllt sein:

- **Externer-Webserver (z. B. Apache oder Nginx)**, auf dem der `index.php`-Code ausgeführt wird.
- **Netzwerkverbindung** zwischen dem Webserver und der Photobooth:
  - Die Photobooth muss über eine direkte IP-Adresse oder über eine Proxy-/VPN-Verbindung zugänglich sein, damit der Webserver die Webhook-Anfragen erfolgreich senden kann.
    (Beispiel Fixe-IP oder DDNS auf Router, welcher mit Wireguard mit der Photobooth verbunden ist)
  
## Übersicht der Ordnerstruktur und Dateien

### Auf dem Webserver

- **`/var/www/html/`**
  - `index.php`: Hauptseite für den Selfie-Upload mit der Upload-Logik und Webhook-Aufruf.
  - `config/config.php`: Konfigurationsdatei mit den URLs und Zugangsdaten.
  - `uploads/`: Verzeichnis für hochgeladene Selfies. serve_image.php
  - `uploads/serve_image.php`: Proxy für den Zugriff auf die Bilder
  - `.htaccess`: Zugriffsschutz für den `uploads`-Ordner.

---

### Auf der Photobooth

- **`/var/www/html/private/webhook/`**
  - `webhook_receiver.php`: Webhook-Empfänger, der das hochgeladene Bild herunterlädt und speichert.
- **`/var/www/html/private/images/uploads`**
  - `images/uploads/`: Verzeichnis auf der Photobooth, in dem die heruntergeladenen Bilder gespeichert und weiterverarbeitet werden.

### Berechtigungen setzen

```bash
sudo chown -R www-data:www-data /var/www/html/private/images/uploads
sudo chmod -R 755 /var/www/html/private/images/uploads
```

## Sicherheitshinweis

Bitte beachte, dass der `uploads`-Ordner und die Webhook-URL Sicherheitsbedenken hervorrufen können. Es ist nicht sicher, die Photobooth über das Internet zugänglich zu machen. Achte auf folgende Punkte:

- **Upload-Beschränkungen**: Stelle sicher, dass nur authentifizierte Benutzer Zugriff auf die Upload-Funktionalität haben, um Missbrauch zu verhindern.
- **Zugriff auf Uploads einschränken**: Schütze den `uploads`-Ordner mit einer `.htaccess`-Datei, um den direkten Zugriff zu verhindern.
- **Webhook-Absicherung**: Überlege, einen Authentifizierungstoken in den Webhook-Headern zu verwenden, um unberechtigte Webhook-Aufrufe zu verhindern.
- **SSL verwenden**: Wenn möglich, stelle sicher, dass sowohl der Webserver als auch die Photobooth HTTPS verwenden, um die Verbindung zu sichern.
