# Weekmenu

Genereert elke week een dinermenu voor zes dagen. Vrijdag is junkfood-dag en
wordt overgeslagen. Draait op gewone PHP-webhosting met MySQL.

## Wat het doet

- Genereert een week aan avondeten, met een rotatie die herhaling tegengaat
- Vraagt bij het genereren wat je in huis hebt en geeft die recepten voorrang
- Eigen recepten toevoegen via een admin paneel
- Losse dag opnieuw gooien zonder de rest van de week kwijt te raken
- Boodschappenlijst van wat je nog moet halen
- Klik op een gerecht voor de ingredienten, hoeveelheden en een korte bereiding
- Knop voor het aantal personen; alle hoeveelheden rekenen mee
- 47 Nederlandse recepten om mee te beginnen, met bereiding

## Installeren op mijndomein.nl

### 1. Database aanmaken

Plesk → **Databases** → *Database toevoegen*. Noteer de naam, gebruiker en
het wachtwoord.

### 2. PHP-versie controleren

Plesk → **Websites & domeinen** → *PHP-instellingen*. Zet de versie op
**8.4** (of 8.3 als 8.4 er nog niet is). Lager dan 8.1 werkt niet.

### 3. Uploaden

Zet de **inhoud** van de map `httpdocs/` in de map `httpdocs` op de server,
via Plesk Bestandsbeheer of met SFTP (FileZilla).

Heb je lokaal een `inc/config.local.php`? Upload die **niet** — dat zijn je
testinstellingen. De installer maakt op de server zijn eigen versie.

De mappenstructuur op de server wordt:

```
httpdocs/
├── index.php
├── admin.php
├── login.php
├── logout.php
├── install.php      ← weghalen na stap 4
├── manifest.json
├── .htaccess
├── api/
│   ├── generate.php
│   └── reroll.php
├── assets/
│   ├── app.css
│   └── app.js
└── inc/
    ├── .htaccess    ← blokkeert directe toegang, moet mee
    ├── config.php
    ├── db.php
    ├── auth.php
    ├── helpers.php
    ├── generator.php
    └── seed_data.php
```

### 4. Installeren

Ga naar `https://jouwdomein.nl/install.php`. Daar vul je in:

- Databaseserver (bij mijndomein: `localhost`)
- Naam van de database, gebruiker en wachtwoord — uit stap 1
- Een voorvoegsel voor de tabellen, optioneel — zie hieronder
- Een adminwachtwoord dat je zelf kiest, minstens 8 tekens

**Voorvoegsel.** Leeg laten mag: de tabellen heten dan `recipe`,
`ingredient`, `menu_week` enzovoort. Deel je die ene database met een
andere site, vul dan bijvoorbeeld `weekmenu_` in. De tabellen heten dan
`weekmenu_recipe` en zitten elkaar niet in de weg. Het voorvoegsel geldt
ook voor de foreign keys, want die namen moeten binnen de hele database
uniek zijn.

De installer test eerst of de verbinding werkt, schrijft de gegevens naar
`inc/config.local.php`, maakt de tabellen aan en zet er 45 recepten in.
Je hoeft dus zelf geen enkel bestand te bewerken.

### 5. install.php weghalen

**Verwijder `install.php` van de server zodra je klaar bent.** Draait hij
nog, dan kan iedereen die het adres kent hem openen.

### 6. Klaar

`https://jouwdomein.nl` toont het weekmenu. Recepten beheren gaat via
`/admin.php`, met het adminwachtwoord dat je bij de installatie koos.

### Op je telefoon zetten

De site is een PWA, dus je kunt hem als app installeren. Dat werkt alleen
via https.

**Android (Chrome):** open de site, menu (drie puntjes) →
*App installeren*. Verschijnt die optie niet, dan staat er *Toevoegen aan
startscherm*; dat werkt ook.

**iPhone (Safari):** open de site, deelknop (vierkantje met pijl) →
*Zet op beginscherm*. Dit moet in Safari; Chrome op iOS kan het niet.

Eenmaal geinstalleerd opent hij zonder adresbalk, met een eigen icoon.
Een service worker bewaart de opmaak en het laatst bekeken menu, zodat de
app ook opent als je in de winkel even geen bereik hebt. Een nieuw menu
genereren vraagt uiteraard wel verbinding.

## Hoe de rotatie werkt

Elk recept krijgt een gewicht, en er wordt geloot met dat gewicht als kans.

| Wat | Effect |
|---|---|
| Langer geleden gegeten | Zwaarder gewicht, dus grotere kans |
| Nog nooit gegeten | Hoog gewicht, komt snel aan de beurt |
| Binnen 3 weken gepland of gegeten | Valt af |
| Ingrediënt in huis | Kans ongeveer ×2 per raak ingrediënt |
| Uitgebreid recept doordeweeks | Gewicht × 0,3 |
| Uitgebreid recept in het weekend | Gewicht × 1,4 |

Daarnaast komt elke soort (pasta, rijst, stamppot…) hoogstens één keer per
week voor.

Heb je te weinig recepten om aan al die regels te voldoen, dan laat de
generator ze stap voor stap los in plaats van een lege dag te tonen. Met
twaalf recepten krijg je dus nog steeds een volle week.

### Bijstellen

In `inc/config.php`:

```php
defined('COOLDOWN_WEEKS') or define('COOLDOWN_WEEKS', 3);   // lager = meer herhaling
defined('PANTRY_BOOST')   or define('PANTRY_BOOST',   0.9); // hoger = voorraad weegt zwaarder
```

## Eigen recepten toevoegen

Via `/admin.php`. Bij **Ingrediënten** vul je alleen de kenmerkende dingen
in, gescheiden door komma's — dus `gehakt, macaroni, ui, kaas` en niet ook
nog peper, zout en olie. Die lijst bepaalt twee dingen: of het recept
omhoog schuift als je iets in huis hebt, en wat er op de boodschappenlijst
komt.

Onderaan het admin paneel staat de **voorraadlijst**: dat zijn de items die
je te zien krijgt in het venster bij het genereren. Houd die kort.

## Bijwerken naar een nieuwe versie

Draait de app al en haal je nieuwe bestanden binnen? Upload ze, ga daarna
eenmalig naar `/upgrade.php` en verwijder dat bestand weer.

Die zet ontbrekende kolommen klaar, vult de bereiding bij de meegeleverde
recepten en voegt nieuwe toe. Je eigen recepten blijven ongemoeid.
Meegeleverde recepten die uit de lijst zijn gehaald worden op non-actief
gezet, niet verwijderd, zodat je weekgeschiedenis heel blijft. Via
Recepten beheren kun je ze weer aanzetten.

### Hoeveelheden

Recepten zijn ingevuld voor vier personen. Met de knop boven de
ingredienten of boven de boodschappenlijst zet je dat hoger of lager; de
app rekent alles om en onthoudt je keuze op dat apparaat.

Bij het toevoegen van een eigen recept zet je de hoeveelheid voorop:
`400 g gehakt`, `2 teen knoflook`, `1 blik tomatenblokjes`. Een regel
zonder hoeveelheid mag ook. Vul bij **Voor hoeveel personen** in waar die
hoeveelheden bij horen.

De boodschappenlijst telt alles per persoon bij elkaar op en
vermenigvuldigt dat met het aantal personen, dus recepten met een
verschillende basis kunnen gewoon door elkaar staan.

Pas je `app.css` of `app.js` aan, verhoog dan ook `CACHE` bovenin
`sw.js`. De service worker ververst bestanden op de achtergrond, maar een
nieuwe cachenaam zorgt dat iedereen het meteen ziet.

## Vereisten

- PHP 8.1 of hoger (8.4 aanbevolen), met PDO MySQL en mbstring
- MySQL of MariaDB
