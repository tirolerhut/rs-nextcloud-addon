=== Slider Revolution – Nextcloud Add-on ===
Requires: WordPress 5.8+, PHP 7.4+, Slider Revolution 6.x

Lädt Bilder aus einer öffentlichen Nextcloud-Ordnerfreigabe in die Mediathek
und erzeugt daraus automatisch Slides in einem Slider-Revolution-6-Slider.

== Installation ==
1. ZIP unter Plugins → Installieren → Plugin hochladen einspielen und aktivieren.
2. Menü: Slider Revolution → Nextcloud (falls nicht vorhanden: Einstellungen → Nextcloud).

== Einrichtung ==
1. In Nextcloud einen Ordner per Link freigeben (Nur lesen; Passwort optional).
2. In Slider Revolution einen Slider mit EINER Vorlagen-Slide gestalten
   (Übergänge, Ken Burns, Text-Ebenen …) und die Slide auf „Unveröffentlicht“ setzen.
   Platzhalter in Text-Ebenen (siehe unten).
3. Im Add-on eine Zuordnung anlegen: Freigabelink, ggf. Unterordner, Ziel-Slider,
   Vorlagen-Slide. „Verbindung testen“ zeigt die gefundenen Bilder.
4. Speichern – der erste Abgleich läuft sofort.

== Platzhalter für Text-Ebenen ==
{{nc_caption}}     Bildbeschreibung aus den Metadaten (Lightroom: „Bildunterschrift“)
                   Quelle: XMP dc:description → IPTC Caption → EXIF ImageDescription
{{nc_headline}}    IPTC-Überschrift
{{nc_meta_title}}  IPTC/XMP-Titel
{{nc_creator}}     Fotograf
{{nc_copyright}}   Copyright
{{nc_date_taken}}  Aufnahmedatum
{{nc_camera}}      Kamera
{{nc_title}}       Dateiname als Titel („IMG_1234“ → „IMG 1234“)
{{nc_filename}}    Dateiname
{{nc_date}}        Änderungsdatum der Datei

Fallback bei leerem Feld:  {{nc_caption|nc_title}}  oder  {{nc_caption|Eigener Text}}
Zeilenumbrüche in der Caption werden als <br> ausgegeben.
Die Caption wird außerdem als Beschriftung und Alt-Text in der Mediathek gespeichert.

== Abgleich ==
- Neue Bilder → neue Slide; geänderte Bilder → Bild wird ersetzt (gleiche Slide,
  gleiche Mediendatei); gelöschte Bilder → Slide wird entfernt (abschaltbar).
- Jedes Foto erscheint genau einmal im Slider:
  · Unveränderte Bilder werden nicht erneut heruntergeladen (ETag bzw. Größe + Datum).
  · Identische Fotos unter verschiedenen Namen werden am Inhalt (SHA-1) erkannt
    und nur einmal übernommen.
  · In Nextcloud umbenannte/verschobene Fotos behalten ihre Slide.
  · Parallele Läufe sind gesperrt; der Fortschritt wird nach jedem Bild gesichert.
  · Jede erzeugte Slide trägt eine Kennung. Geht die interne Liste verloren
    (Neuinstallation, Zuordnung neu angelegt), werden vorhandene Slides übernommen
    statt neu angelegt. Doppelte Slides aus älteren Versionen werden entfernt.
  · Im Editor kopierte Slides: veröffentlicht = Duplikat (wird entfernt),
    unveröffentlicht = Vorlage (bleibt unangetastet).
- Dateinamen bleiben exakt erhalten (inkl. Leerzeichen, Umlaute, Klammern).
  Die Bilder liegen in wp-content/uploads/nextcloud/<Zuordnung>/. Entfernt werden
  nur Zeichen, die in Dateisystemen/URLs nicht funktionieren: / \ : * ? " < > | # %
  WordPress verkleinert diese Bilder nicht zu „-scaled“; für kleinere Dateien im
  Slider die Option „Bildgröße“ verwenden (z. B. „2048x2048“ oder „large“).
  Bilder aus Version 1.2 werden beim ersten Lauf lokal auf den Originalnamen umgestellt.
- Automatisch per WP-Cron (stündlich / 2× täglich / täglich) oder manuell.
- WP-CLI: wp rsnc list | wp rsnc sync <id> | wp rsnc sync --all
- Tipp: Für zuverlässige Läufe echten Server-Cron statt WP-Cron verwenden.

== Updates über GitHub ==
Das Add-on aktualisiert sich über das normale WordPress-Update-System
(Plugins-Liste, Dashboard → Aktualisierungen, auch automatische Updates).

Einrichtung (einmalig):
1. Den Inhalt des Plugin-Ordners in ein GitHub-Repository legen
   (rs-nextcloud-addon.php muss im Hauptverzeichnis liegen).
2. In WordPress unter Slider Revolution → Nextcloud → „Updates über GitHub“
   das Repository eintragen (owner/repo). Bei privaten Repositories zusätzlich
   einen Fine-grained Token mit Leserecht auf „Contents“.
   Alternativ in wp-config.php:
     define( 'RSNC_GITHUB_REPO',  'owner/repo' );
     define( 'RSNC_GITHUB_TOKEN', 'github_pat_…' );

Neue Version veröffentlichen:
1. Version an BEIDEN Stellen in rs-nextcloud-addon.php erhöhen
   („Version:“ im Kopf und RSNC_VERSION), committen, pushen.
2. Tag setzen und pushen:  git tag v1.3.0 && git push origin v1.3.0
3. Der mitgelieferte Workflow (.github/workflows/release.yml) prüft die Version,
   baut rs-nextcloud-addon.zip und erstellt das Release.
   Ohne Workflow genügt auch ein manuell erstelltes Release mit Tag v1.3.0.

WordPress prüft etwa alle 12 Stunden; „Speichern & nach Updates suchen“ prüft sofort.
Nur veröffentlichte Releases zählen – Entwürfe und Pre-Releases werden ignoriert.

== Hinweise ==
- Unterstützt den neuen (NC 29+, /public.php/dav/files/TOKEN) und den
  alten WebDAV-Endpunkt (/public.php/webdav) automatisch.
- Bilder liegen danach lokal in der Mediathek – die Website lädt nichts
  live von Nextcloud.
- Vom Add-on erzeugte Slides nicht im Editor bearbeiten – Änderungen werden
  beim nächsten Abgleich überschrieben. Stattdessen die Vorlage ändern: Änderungen
  an der Vorlage werden beim nächsten Abgleich auf alle Slides angewendet.
- Das Freigabe-Passwort wird in der WordPress-Datenbank gespeichert.
- Filter für Entwickler: rsnc_slide_params ($params, $file, $attachment_id, $mapping),
  rsnc_placeholders ($vars, …) für eigene Variablen, rsnc_image_meta ($meta, $path)
  Action: rsnc_after_sync ($mapping_id, $result)
- Slider Revolution 7 nutzt eine neue Datenstruktur; dieses Add-on ist für 6.x gebaut.
