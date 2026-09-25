# Weekmenu

Genereert elke week een dinermenu, standaard voor zes dagen (het aantal
dagen is instelbaar). Vrijdag is junkfood-dag en wordt overgeslagen. Draait
op gewone PHP-webhosting met MySQL.

## Wat het doet

- Genereert een week aan avondeten, met een rotatie die herhaling tegengaat
- Vraagt bij het genereren wat je in huis hebt en geeft die recepten voorrang
- Eigen recepten toevoegen via een admin paneel
- Losse dag opnieuw gooien zonder de rest van de week kwijt te raken
- Boodschappenlijst van wat je nog moet halen
- Klik op een gerecht voor de ingredienten, hoeveelheden en een korte bereiding
- Aantal personen per dag; de boodschappenlijst telt dat vanzelf op
- Aantal dagen waar een menu voor gemaakt wordt is instelbaar, bijvoorbeeld
  een werkweek van vijf dagen zonder het weekend
- Recept genoeg voor twee dagen? Zet "restjes" aan de knop, twee dagen later
- Houdt bij het kiezen rekening met actuele aanbiedingen bij AH, Jumbo,
  Aldi en PLUS, en laat op de boodschappenlijst zien welk product in de
  aanbieding is en bij welke winkel
- 47 Nederlandse recepten om mee te beginnen, met bereiding
- Kijken mag iedereen; gebruikers met een eigen rol (lezer, bewerker, beheerder)
- Boodschappen afvinken wordt per week bewaard en verschijnt binnen een paar
  seconden ook op andere telefoons
- Exportknop zet de lijst in de Bring! app
- Klokje per dag: wanneer stond dit gerecht vorige keer op tafel
- Recepten als favoriet of "zelden" markeren
- Seizoen per recept: stamppot en erwtensoep alleen in de koude maanden
- Recept importeren van een link (Leukerecepten, 24Kitchen, ...), met een
  venster om de ingrediënten aan je eigen lijst te koppelen
- "Ligt in de vriezer": de avond ervoor een melding om het eruit te halen
- Scherm blijft aan zolang een recept openstaat
- Pushmeldingen op je telefoon (zie **Meldingen**)
- Delen, afdrukken en een back-up van de hele database

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
├── bring.php
├── bring-export.php
├── backup.php
├── cron.php         ← meldingen, dagelijks via Geplande taken (zie Meldingen)
├── install.php      ← weghalen na stap 4
├── manifest.json
├── sw.js
├── .htaccess
├── api/
│   ├── generate.php
│   ├── reroll.php
│   ├── leftover.php
│   ├── servings.php
│   ├── check.php
│   ├── lock.php
│   ├── deal_exclude.php
│   ├── recipe.php
│   ├── checks.php
│   ├── push.php
│   ├── thaw.php
│   └── admin_*.php
├── assets/
│   ├── app.css
│   └── app.js
└── inc/
    ├── .htaccess    ← blokkeert directe toegang, moet mee
    ├── config.php
    ├── db.php
    ├── auth.php
    ├── helpers.php
    ├── settings.php
    ├── generator.php
    ├── deals.php
    ├── push.php
    ├── admin_helpers.php
    ├── import.php
    └── seed_data.php
```

### 4. Installeren

Ga naar `https://jouwdomein.nl/install.php`. Daar vul je in:

- Databaseserver (bij mijndomein: `localhost`)
- Naam van de database, gebruiker en wachtwoord — uit stap 1
- Een voorvoegsel voor de tabellen, optioneel — zie hieronder
- Aantal dagen waar het weekmenu een gerecht voor kiest, standaard 7 —
  later aan te passen bij Instellingen, zie hieronder
- Een gebruikersnaam en wachtwoord voor de eerste beheerder, minstens 8 tekens

**Voorvoegsel.** Leeg laten mag: de tabellen heten dan `recipe`,
`ingredient`, `menu_week` enzovoort. Deel je die ene database met een
andere site, vul dan bijvoorbeeld `weekmenu_` in. De tabellen heten dan
`weekmenu_recipe` en zitten elkaar niet in de weg. Het voorvoegsel geldt
ook voor de foreign keys, want die namen moeten binnen de hele database
uniek zijn.

De installer test eerst of de verbinding werkt, schrijft de gegevens naar
`inc/config.local.php`, maakt de tabellen aan en zet er 47 recepten in.
Je hoeft dus zelf geen enkel bestand te bewerken.

### 5. install.php weghalen

**Verwijder `install.php` van de server zodra je klaar bent.** Draait hij
nog, dan kan iedereen die het adres kent hem openen.

### 6. Klaar

`https://jouwdomein.nl` toont het weekmenu. Log in met de beheerder die je
bij de installatie aanmaakte; andere gebruikers maak je aan in Recepten
beheren, tabblad **Gebruikers**.

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
| Favoriet / zelden | Gewicht × 2 / × 0,3 |
| Uitgebreid recept doordeweeks | Gewicht × 0,3 |
| Uitgebreid recept in het weekend | Gewicht × 1,4 |

Daarnaast komt elke soort (pasta, rijst, stamppot…) hoogstens één keer per
week voor, en een recept met een seizoen alleen in die maanden. De maand
van de donderdag telt, dus een week die over de maandgrens loopt hoort bij
de maand met de meeste dagen.

Heb je te weinig recepten om aan al die regels te voldoen, dan laat de
generator ze stap voor stap los in plaats van een lege dag te tonen. Met
twaalf recepten krijg je dus nog steeds een volle week.

### Bijstellen

In `inc/config.php`:

```php
defined('COOLDOWN_WEEKS') or define('COOLDOWN_WEEKS', 3);   // lager = meer herhaling
defined('PANTRY_BOOST')   or define('PANTRY_BOOST',   0.9); // hoger = voorraad weegt zwaarder
```

## Aanbiedingen

Bij het genereren van een weekmenu checkt de app (best effort, hooguit
eens per paar uur) of er actuele aanbiedingen zijn bij Albert Heijn,
Jumbo, Aldi en PLUS. Dat gaat via de onofficiele, niet door ons gemaakte
dienst [prijsprofeet.nl](https://www.prijsprofeet.nl/api) - geen sleutel
nodig voor het gratis niveau. Een recept waarvan een kenmerkend ingredient
nu in de aanbieding is krijgt een iets grotere kans om gekozen te worden,
op dezelfde manier als voorraad dat al deed.

Op de boodschappenlijst staat bij zo'n ingredient een label met de winkel
(en bijvoorbeeld "+2" als het bij meer winkels in de aanbieding is); tik
of klik erop voor een venster met het product, de winkel en de prijs bij
elke winkel waar het in de aanbieding is.

Is prijsprofeet.nl niet bereikbaar, dan genereert de app gewoon door
zonder kortingsweging - dit is altijd een extraatje, nooit een vereiste.
Gaat een week op slot (naar Bring), dan worden de kortingen van dat moment
vastgelegd, zodat de boodschappenlijst blijft kloppen ook als je later
zonder bereik in de winkel staat, of de actie inmiddels voorbij blijkt.

Reroll en de paginaweergave zelf checken nooit opnieuw bij prijsprofeet.nl
- alleen het genereren van een weekmenu doet dat, en dat hooguit eens per
`DEALS_REFRESH_HOURS` uur. Dat houdt het aantal aanroepen ruim onder hun
rate limit.

De zoek-api matcht op woorden, niet op producten: "gehakt" levert ook
"Mix voor gehakt" en "Gebraden gehakt" op. De app laat daarom alleen
producten door waarvan de naam niets anders zegt dan het ingredient, het
merk, de verpakking en een algemene aanduiding (rode, bio, kruimig,
belegen, ... - `DEAL_VARIANT_WORDS` in `inc/deals.php`). "Gemengd gehakt"
komt erdoor, "Gebraden gehakt" niet. Liever een aanbieding missen dan een
verkeerde op de lijst; mist er een voor de hand liggende aanduiding, zet
hem dan in die lijst.

Bijgewerkt vanaf een versie met "geleerde woorden"? Dan gooit
`upgrade.php` die tabel weg en leegt het de kortingscache; het volgende
weekmenu dat je genereert haalt de aanbiedingen opnieuw op.

Glipt er toch iets doorheen, klik dan in het kortingsvenster op
**klopt niet, uitsluiten** bij dat product. Het verdwijnt meteen van de boodschappenlijst en komt ook bij
een volgende verversing niet meer terug voor dat ingredient bij die
winkel - een andere aanbieding bij diezelfde winkel kan later gewoon weer
verschijnen.

Per ongeluk een verkeerd product uitgesloten? In Recepten beheren,
tabblad **Kortingen**, staat de lijst met een "wis"-knop erbij om dat
terug te draaien. Bovenaan dat tabblad staat ook wanneer het ophalen voor
het laatst lukte; staat daar dat de laatste poging mislukte, dan is
prijsprofeet.nl misschien veranderd of plat.

### Bijstellen

In `inc/config.php`:

```php
defined('DEALS_BOOST')         or define('DEALS_BOOST',         0.6);  // hoger = aanbieding weegt zwaarder
defined('DEALS_REFRESH_HOURS') or define('DEALS_REFRESH_HOURS', 12);   // hoe vaak checken
```

## Eigen recepten toevoegen

Via `/admin.php`. Bij **Ingrediënten** vul je alleen de kenmerkende dingen
in, gescheiden door komma's — dus `gehakt, macaroni, ui, kaas` en niet ook
nog peper, zout en olie. Die lijst bepaalt twee dingen: of het recept
omhoog schuift als je iets in huis hebt, en wat er op de boodschappenlijst
komt. Tijdens het typen krijg je suggesties van ingrediënten die al
bestaan, zodat "ui" en "uien" niet als twee losse dingen op de
boodschappenlijst belanden.

Onderaan het admin paneel staat de **voorraadlijst**: dat zijn de items die
je te zien krijgt in het venster bij het genereren. Houd die kort.

Vink je bij een recept **"Genoeg voor restjes"** aan, dan verschijnt in het
weekmenu twee dagen later een knop om die dag zonder koken over te slaan.

Bij **Hoe vaak** kies je *Favoriet* (komt vaker langs) of *Zelden* (komt
minder vaak langs). Helemaal niet meer? Pauzeer het recept. In de tabel
zie je per recept wanneer het voor het laatst op tafel stond.

Bij **Seizoen** vink je de maanden aan waarin het recept mag langskomen;
niets aangevinkt is het hele jaar. De meegeleverde winterkost (stamppotten,
erwtensoep, hachee, ...) staat al op oktober t/m maart. In de tabel zie je
het seizoen als bijvoorbeeld `okt-mrt`.

### Importeren van een link

Met **Importeer** plak je de link van een recept op een receptensite. De
app leest het recept zoals de site het voor Google klaarzet (schema.org
in JSON-LD): naam, aantal personen, ingrediënten, bereiding en ongeveer
hoeveel werk het is. Daarna krijg je per ingrediënt te zien wat de site
schrijft en wat het bij ons wordt:

- een bestaand ingrediënt (de beste gok staat al klaar, `rundergehakt`
  wordt `gehakt`, `2 dl kookroom` wordt `200 ml room`);
- een nieuw ingrediënt, met een naam die je nog kunt aanpassen;
- of niet meenemen (zout, peper en olie staan daar standaard op).

Je keuzes worden onthouden: de volgende keer dat een site "tomatensaus"
schrijft, staat jouw keuze meteen klaar. Daarna opent het gewone
receptvenster, zodat je alles nog kunt nakijken voor het opslaan.

Niet elke site laat zich uitlezen: Allerhande (AH) weigert het ophalen door
een server. Dan opent vanzelf **Zelf plakken**: kopieer de ingrediënten
van de receptpagina en plak ze erin, eventueel met de bereiding erbij. Dat
hoeveelheid en naam bij AH op losse regels staan ("400 g", dan
"kastanjechampignons") is geen probleem. Daarna volgt hetzelfde
koppelvenster.

## Restjes / dubbele kookdag

Kookte je maandag iets dat ook voor dinsdag genoeg is? Zet bij dat recept
in Recepten beheren **"Genoeg voor restjes (2 dagen later)"** aan. Twee
dagen na zo'n gerecht verschijnt in het weekmenu de knop **"Restjes van
&lt;dag&gt;"**. Klik je erop, dan verschijnt op die dag geen nieuw gerecht:
je eet gewoon door van wat er al was, dus die dag telt ook niet extra mee
op de boodschappenlijst.

Dit gaat altijd met de hand — de generator plant nooit uit zichzelf een
restjesdag in. Wil je het toch los weer maken, klik dan nogmaals op de
(actief getoonde) knop.

## Wie mag wat

Het weekmenu is voor iedereen te bekijken: gerechten, bereiding,
hoeveelheden en de boodschappenlijst. Handig om even te laten zien of
door te sturen.

Wie inlogt krijgt een van drie rollen:

| Rol | Mag |
|---|---|
| Lezer | Kijken, net als zonder inloggen, en meldingen aanzetten |
| Bewerker | Ook: afvinken, menu maken en wijzigen, naar Bring, recepten beheren |
| Beheerder | Ook: instellingen, voorraadlijst, kortingen, gebruikers, back-up |

Gebruikers beheer je in Recepten beheren, tabblad **Gebruikers**. Er gaat
geen mail rond: een beheerder zet zelf een nieuw wachtwoord. Er blijft
altijd minstens één beheerder over.

De knoppen zijn niet alleen verborgen: de api-bestanden weigeren het ook
(401 zonder login, 403 met een te lage rol). Na vijf verkeerde
inlogpogingen vanaf hetzelfde adres moet je een kwartier wachten.

## Meldingen

Ingelogd staat er een belletje bovenin. Tik erop om meldingen op dat
apparaat aan te zetten; je krijgt meteen een testmelding. Op Android en de
computer werkt dat in de browser; op een iPhone alleen als de app op het
beginscherm staat (iOS 16.4 of nieuwer) en je hem daarvandaan opent.

| Melding | Naar |
|---|---|
| Lijst naar Bring gestuurd, week op slot | Iedereen |
| Een verstuurde week weer ontgrendeld | Beheerders |
| Nog geen menu voor de komende week | Bewerkers en beheerders |
| Morgen staat iets op het menu dat nog in de vriezer ligt | Iedereen |

Wie het zelf deed krijgt geen melding.

De laatste twee komen van `cron.php`. Zet die in Plesk onder **Geplande taken**
→ *Taak toevoegen* → *Een PHP-script uitvoeren*, met als script
`httpdocs/cron.php`, **elke dag** om bijvoorbeeld 18:00. Hij kijkt naar
morgen: ligt dat gerecht in de vriezer, dan krijg je een melding; is
morgen maandag en is er nog geen weekmenu, dan ook. Via de browser doet
`cron.php` niets.

"In de vriezer" zet je per dag aan: klik op het gerecht en tik op **Ligt in
de vriezer?**. Op de kaart van die dag staat dan een sneeuwvlokje. Kies je
een ander gerecht voor die dag, dan gaat het vanzelf weer uit.

De sleutels voor de meldingen maakt de app zelf aan, de eerste keer dat
iemand inlogt. Ze staan in de tabel `setting`; haal je die weg, dan moet
iedereen meldingen opnieuw aanzetten.

## Delen, afdrukken en back-up

Bovenin staan knoppen om de link naar de week te delen en om menu en
boodschappenlijst af te drukken (zonder knoppen en labels).

Een beheerder kan onder **Instellingen** een back-up downloaden: alle
tabellen als `.sql`, terug te zetten via phpMyAdmin in Plesk. Daar staan
ook de versleutelde wachtwoorden in, dus bewaar hem niet zomaar ergens.

## De boodschappenlijst

Alles wat de recepten van de week nodig hebben staat erop, ook wat je bij
het genereren aanvinkte als "heb ik al". Dat staat doorgestreept, met de
hoeveelheid erbij. Zo zie je wat je deze week nodig hebt en kun je het
weer aanzetten als de pot toch bijna leeg is.

Wat doorgestreept staat gaat niet mee naar Bring.

Staat een ingredient in de aanbieding, dan zie je er een label bij met de
winkel — zie **Aanbiedingen** hierboven. Die winkel gaat ook mee naar
Bring (de prijs niet), zodat je ook daar kunt zien waar het voordeligst is
en kunt kiezen of je ervoor naar die winkel gaat.

### Naar Bring!

Ingelogd staat er boven de lijst een knop **Naar Bring!**. Die opent de
Bring! app met alles wat je nog moet halen.

**De week gaat daarna op slot.** Het menu ligt dan vast: geen ander
gerecht, geen ander aantal personen, niet opnieuw genereren. Anders klopt
wat er in Bring staat niet meer met wat je thuis kookt. Afstrepen blijft
gewoon werken, want daar ben je in de winkel mee bezig.

Toch nog iets wijzigen? Klik op **Ontgrendelen** in de balk bovenaan. Wat
al in Bring staat verandert daar niet meer door.

Het werkt via `bring.php`, een pagina die de lijst als schema.org-recept
toont. Bring haalt die pagina zelf op vanaf hun servers, dus er staat
bewust geen login op. Er staat ook niets gevoeligs op: alleen wat er
deze week gekocht moet worden.

Werkt het niet, controleer de pagina dan met de integratiecheck van Bring
zelf op getbring.com/en/integration-check; vul daar het volledige adres
van `bring.php?week=JJJJ-MM-DD` in.

## Bijwerken naar een nieuwe versie

Draait de app al en haal je nieuwe bestanden binnen? Upload ze, ga daarna
eenmalig naar `/upgrade.php` en verwijder dat bestand weer.

Die zet ontbrekende kolommen en tabellen klaar, vult de bereiding bij de meegeleverde
recepten en voegt nieuwe toe. Je eigen recepten blijven ongemoeid.
Meegeleverde recepten die uit de lijst zijn gehaald worden op non-actief
gezet, niet verwijderd, zodat je weekgeschiedenis heel blijft. Via
Recepten beheren kun je ze weer aanzetten.

Kom je van een versie met één adminwachtwoord, dan maakt `upgrade.php` de
eerste beheerder aan: gebruikersnaam `admin`, met het wachtwoord waarmee je
tot dan toe inlogde. Maak daarna je eigen accounts aan.

### Hoeveelheden en aantal personen

Elke dag in het weekmenu heeft zijn eigen aantal personen. Standaard drie;
dat stel je in bij Recepten beheren onder Instellingen. Eet er zondag
iemand mee, dan zet je alleen die dag op vier met de knop op de dagregel
of in het receptvenster. De boodschappenlijst telt de hele week op met het
aantal van elke dag erbij gerekend.

Een nieuw weekmenu begint altijd weer op de standaard, zodat een gast van
vorige week niet blijft hangen.

De recepten zelf zijn geschreven voor vier personen; dat staat per recept
en de app rekent het om.

### Aantal dagen in het weekmenu

Bij Instellingen (of tijdens het installeren) stel je in vanaf maandag
voor hoeveel dagen een nieuw weekmenu een gerecht kiest, van 1 tot en met
7. Bij bijvoorbeeld 5 blijven zaterdag en zondag leeg — geen kaart, geen
boodschappen voor die dagen. Een week die je al eerder genereerde
verandert niet mee als je deze instelling later aanpast; pas een nieuwe
generatie van die week houdt zich aan de nieuwe waarde.

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

- PHP 8.1 of hoger (8.4 aanbevolen), met PDO MySQL, mbstring, curl en openssl
- MySQL of MariaDB
