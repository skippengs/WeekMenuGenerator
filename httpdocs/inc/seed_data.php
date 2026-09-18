<?php
declare(strict_types=1);
if (!defined('WEEKMENU')) { http_response_code(403); exit('Forbidden'); }

/**
 * Startvoorraad aan ingredienten.
 * [naam, groep, staat_in_voorraadpopup]
 *
 * Alleen items met 1 verschijnen in het "heb ik al in huis"-venster.
 * Dat houdt die lijst kort genoeg om in tien tellen af te vinken.
 */
function seedIngredients(): array
{
    return [
        // Vlees & vis
        ['gehakt',          'vlees', 1],
        ['kipfilet',        'vlees', 1],
        ['spekblokjes',     'vlees', 1],
        ['speklapjes',      'vlees', 1],
        ['rookworst',       'vlees', 1],
        ['shoarmavlees',    'vlees', 1],
        ['runderlappen',    'vlees', 1],
        ['zalmfilet',       'vlees', 1],
        ['vissticks',       'vlees', 1],
        ['ham',             'vlees', 0],
        ['saucijzen',       'vlees', 0],
        ['tonijn uit blik', 'vlees', 0],

        // Basis
        ['aardappelen',  'basis', 1],
        ['pasta',        'basis', 1],
        ['macaroni',     'basis', 1],
        ['rijst',        'basis', 1],
        ['mie',          'basis', 1],
        ['wraps',        'basis', 1],
        ['eieren',       'basis', 1],
        ['stokbrood',    'basis', 0],
        ['pitabroodjes', 'basis', 0],
        ['lasagnebladen', 'basis', 0],
        ['bloem',        'basis', 0],
        ['pizzabodem',   'basis', 0],

        // Groente
        ['ui',             'groente', 1],
        ['knoflook',       'groente', 1],
        ['wortel',         'groente', 1],
        ['broccoli',       'groente', 1],
        ['bloemkool',      'groente', 1],
        ['sperziebonen',   'groente', 1],
        ['boerenkool',     'groente', 1],
        ['zuurkool',       'groente', 1],
        ['andijvie',       'groente', 1],
        ['rode kool',      'groente', 1],
        ['witlof',         'groente', 1],
        ['spinazie',       'groente', 1],
        ['prei',           'groente', 1],
        ['paprika',        'groente', 1],
        ['champignons',    'groente', 1],
        ['courgette',      'groente', 1],
        ['tomatenblokjes', 'groente', 1],
        ['doperwten',      'groente', 0],
        ['taugé',          'groente', 0],
        ['sla',            'groente', 0],
        ['tomaat',         'groente', 0],
        ['komkommer',      'groente', 0],
        ['appel',          'groente', 0],

        // Zuivel & rest
        ['kaas',          'rest', 1],
        ['melk',          'rest', 1],
        ['room',          'rest', 1],
        ['boter',         'rest', 1],
        ['bruine bonen',  'rest', 0],
        ['kapucijners',   'rest', 0],
        ['kidneybonen',   'rest', 0],
        ['spliterwten',   'rest', 0],
        ['tomatenpuree',  'rest', 0],
        ['kerriepoeder',  'rest', 0],
        ['nasikruiden',   'rest', 0],
        ['ketjap',        'rest', 0],
        ['satésaus',      'rest', 0],
        ['knoflooksaus',  'rest', 0],
        ['bouillonblokje', 'rest', 0],
        ['appelmoes',     'rest', 0],
    ];
}

/**
 * Startset recepten: gewone Nederlandse doordeweekse kost.
 * [naam, categorie, effort (1-3), alleen_weekend, notitie, url, ingredienten]
 *
 * De ingredienten zijn bewust alleen de kenmerkende: die bepalen of een
 * recept omhoog schuift als je het in huis hebt. Peper, zout en olie
 * staan er dus niet tussen.
 */
function seedRecipes(): array
{
    return [
        ['Macaroni met gehakt', 'pasta', 1, 0, 'Klassieker. Kaas eroverheen en onder de grill.', null,
            ['macaroni', 'gehakt', 'tomatenblokjes', 'ui', 'paprika', 'kaas']],

        ['Spaghetti bolognese', 'pasta', 2, 0, null, null,
            ['pasta', 'gehakt', 'tomatenblokjes', 'ui', 'knoflook', 'tomatenpuree', 'kaas']],

        ['Tonijnpasta met courgette', 'pasta', 1, 0, 'Kwartiertje werk.', null,
            ['pasta', 'tonijn uit blik', 'courgette', 'knoflook', 'room']],

        ['Pasta pesto met kip', 'pasta', 1, 0, null, null,
            ['pasta', 'kipfilet', 'champignons', 'room', 'kaas']],

        ['Nasi goreng', 'rijst', 2, 0, 'Lekker met een gebakken ei erop.', null,
            ['rijst', 'kipfilet', 'eieren', 'ui', 'prei', 'nasikruiden', 'ketjap']],

        ['Nasi met saté', 'rijst', 2, 0, null, null,
            ['rijst', 'kipfilet', 'satésaus', 'ui', 'nasikruiden', 'taugé']],

        ['Bami goreng', 'noedels', 2, 0, null, null,
            ['mie', 'kipfilet', 'prei', 'taugé', 'ketjap', 'eieren']],

        ['Roerbakgroenten met kip en mie', 'noedels', 1, 0, 'Alles in één wok.', null,
            ['mie', 'kipfilet', 'paprika', 'broccoli', 'ketjap', 'knoflook']],

        ['Kip kerrie met rijst', 'rijst', 2, 0, null, null,
            ['rijst', 'kipfilet', 'kerriepoeder', 'ui', 'room', 'prei']],

        ['Chili con carne met rijst', 'rijst', 2, 0, null, null,
            ['rijst', 'gehakt', 'kidneybonen', 'tomatenblokjes', 'paprika', 'ui']],

        ['Risotto met champignons', 'rijst', 3, 0, 'Vraagt roeren, dus eerder een weekendklus.', null,
            ['rijst', 'champignons', 'ui', 'room', 'kaas', 'bouillonblokje']],

        ['Boerenkoolstamppot met rookworst', 'stamppot', 2, 0, null, null,
            ['boerenkool', 'aardappelen', 'rookworst', 'spekblokjes', 'melk']],

        ['Hutspot met klapstuk', 'stamppot', 3, 0, null, null,
            ['wortel', 'ui', 'aardappelen', 'runderlappen', 'boter']],

        ['Zuurkoolstamppot met spek', 'stamppot', 2, 0, null, null,
            ['zuurkool', 'aardappelen', 'spekblokjes', 'rookworst', 'melk']],

        ['Andijviestamppot', 'stamppot', 1, 0, 'Rauwe andijvie door de warme puree.', null,
            ['andijvie', 'aardappelen', 'spekblokjes', 'melk']],

        ['Spinaziestamppot met ei', 'stamppot', 1, 0, null, null,
            ['spinazie', 'aardappelen', 'eieren', 'melk', 'kaas']],

        ['Gehaktballen met aardappelen en groente', 'aardappel', 2, 0, 'Het klassieke AVG-tje.', null,
            ['gehakt', 'aardappelen', 'sperziebonen', 'ui', 'appelmoes']],

        ['Kipfilet met krieltjes en sperziebonen', 'aardappel', 2, 0, null, null,
            ['kipfilet', 'aardappelen', 'sperziebonen', 'boter']],

        ['Bloemkool met gehakt en aardappelen', 'aardappel', 1, 0, null, null,
            ['bloemkool', 'gehakt', 'aardappelen', 'kaas']],

        ['Vissticks met puree en worteltjes', 'aardappel', 1, 0, 'Altijd goed bij de kinderen.', null,
            ['vissticks', 'aardappelen', 'wortel', 'melk']],

        ['Speklapjes met sperziebonen en aardappelen', 'aardappel', 2, 0, null, null,
            ['speklapjes', 'sperziebonen', 'aardappelen', 'ui']],

        ['Rode kool met appeltjes en worst', 'aardappel', 2, 0, null, null,
            ['rode kool', 'appel', 'aardappelen', 'rookworst']],

        ['Broccoli met aardappelen en kaassaus', 'aardappel', 1, 0, null, null,
            ['broccoli', 'aardappelen', 'kaas', 'melk', 'ham']],

        ['Ovenschotel met aardappel en broccoli', 'oven', 2, 0, null, null,
            ['aardappelen', 'broccoli', 'gehakt', 'kaas', 'room']],

        ['Lasagne', 'oven', 3, 0, 'Even werk, maar je eet er twee dagen van.', null,
            ['lasagnebladen', 'gehakt', 'tomatenblokjes', 'kaas', 'room', 'ui']],

        ['Macaroni-ovenschotel', 'oven', 2, 0, null, null,
            ['macaroni', 'gehakt', 'tomatenblokjes', 'kaas', 'paprika']],

        ['Witlof met ham en kaas uit de oven', 'oven', 2, 0, null, null,
            ['witlof', 'ham', 'kaas', 'aardappelen', 'melk']],

        ['Zelfgemaakte pizza', 'oven', 2, 0, 'Iedereen belegt zijn eigen helft.', null,
            ['pizzabodem', 'tomatenpuree', 'kaas', 'paprika', 'champignons', 'ham']],

        ['Griekse ovenschotel met gehakt', 'oven', 3, 1, 'Weekendwerk.', null,
            ['gehakt', 'aardappelen', 'courgette', 'tomatenblokjes', 'kaas', 'room']],

        ['Erwtensoep met roggebrood', 'soep', 3, 1, 'Maak een grote pan, wordt de volgende dag beter.', null,
            ['spliterwten', 'rookworst', 'wortel', 'prei', 'aardappelen', 'bouillonblokje']],

        ['Tomatensoep met stokbrood', 'soep', 1, 0, null, null,
            ['tomatenblokjes', 'gehakt', 'ui', 'bouillonblokje', 'stokbrood']],

        ['Groentesoep met balletjes', 'soep', 2, 0, null, null,
            ['wortel', 'prei', 'gehakt', 'bouillonblokje', 'stokbrood']],

        ['Courgettesoep', 'soep', 1, 0, null, null,
            ['courgette', 'ui', 'room', 'bouillonblokje', 'stokbrood']],

        ['Wraps met kip', 'wraps', 1, 0, null, null,
            ['wraps', 'kipfilet', 'paprika', 'sla', 'tomaat', 'kaas']],

        ['Shoarma met pita en knoflooksaus', 'wraps', 1, 0, null, null,
            ['shoarmavlees', 'pitabroodjes', 'knoflooksaus', 'sla', 'tomaat', 'komkommer']],

        ['Hamburgers met salade en aardappelpartjes', 'wraps', 2, 0, null, null,
            ['gehakt', 'aardappelen', 'sla', 'tomaat', 'kaas', 'ui']],

        ['Hachee met rode kool', 'vlees', 3, 1, 'Uren sudderen, dus voor het weekend.', null,
            ['runderlappen', 'ui', 'rode kool', 'aardappelen', 'bouillonblokje']],

        ['Draadjesvlees met aardappelen', 'vlees', 3, 1, null, null,
            ['runderlappen', 'ui', 'aardappelen', 'boter', 'bouillonblokje']],

        ['Saucijzen met zuurkool', 'vlees', 2, 0, null, null,
            ['saucijzen', 'zuurkool', 'aardappelen', 'spekblokjes']],

        ['Zalmfilet met puree en spinazie', 'vis', 2, 0, null, null,
            ['zalmfilet', 'aardappelen', 'spinazie', 'room', 'melk']],

        ['Bruine bonen met spek', 'bonen', 2, 0, null, null,
            ['bruine bonen', 'spekblokjes', 'ui', 'aardappelen']],

        ['Kapucijners met spek en zilveruitjes', 'bonen', 2, 0, null, null,
            ['kapucijners', 'spekblokjes', 'ui', 'aardappelen']],

        ['Pannenkoeken', 'overig', 1, 0, 'Avondje niet koken.', null,
            ['bloem', 'melk', 'eieren', 'spekblokjes', 'boter']],

        ['Omelet met spek en brood', 'overig', 1, 0, null, null,
            ['eieren', 'spekblokjes', 'kaas', 'stokbrood', 'champignons']],

        ['Gevulde paprika uit de oven', 'oven', 2, 0, null, null,
            ['paprika', 'gehakt', 'rijst', 'tomatenblokjes', 'kaas']],
    ];
}
