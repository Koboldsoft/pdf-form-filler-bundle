<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Koboldsoft\PdfFormFillerBundle\Service\PdfFormFiller;

require dirname(__DIR__, 5) . '/vendor/autoload.php';

final class PdfFormFillerTestConnection extends Connection
{
    private array $standorte;

    public function __construct(array $standorte)
    {
        $this->standorte = $standorte;
    }

    public function fetchAssociative(string $query, array $params = [], array $types = [])
    {
        if (strpos($query, 'FROM mm_standort') === false) {
            throw new RuntimeException('Unexpected query: ' . $query);
        }

        $id = (int) ($params[0] ?? 0);

        return $this->standorte[$id] ?? false;
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

function callPrivateMethod(object $object, string $method, array $args = [])
{
    $reflectionMethod = new ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $args);
}

$standorte = [
    5 => [
        'strasse' => 'Boxhagener Str. 77/78',
        'plz' => '10245',
        'ort' => 'Berlin',
    ],
    6 => [
        'strasse' => 'Am Bahnhof',
        'plz' => '10115',
        'ort' => 'Berlin',
    ],
    7 => [
        'strasse' => null,
        'plz' => null,
        'ort' => null,
    ],
];

$filler = new PdfFormFiller(new PdfFormFillerTestConnection($standorte));

$empty = [
    'strasse' => '',
    'hausnummer' => '',
    'plz' => '',
    'ort' => '',
];

assertSameValue(
    [
        'strasse' => 'Boxhagener Str.',
        'hausnummer' => '77/78',
        'plz' => '10245',
        'ort' => 'Berlin',
    ],
    callPrivateMethod($filler, 'loadStandortAdresse', [5]),
    'Fall 1: Gueltiger Standort'
);

assertSameValue($empty, callPrivateMethod($filler, 'loadStandortAdresse', [null]), 'Fall 2: Standort-ID ist null');
assertSameValue($empty, callPrivateMethod($filler, 'loadStandortAdresse', ['']), 'Fall 3: Standort-ID ist leer');
assertSameValue($empty, callPrivateMethod($filler, 'loadStandortAdresse', [999999]), 'Fall 4: Standort existiert nicht');
assertSameValue($empty, callPrivateMethod($filler, 'loadStandortAdresse', ['null']), 'Standort-ID ist String null');
assertSameValue($empty, callPrivateMethod($filler, 'loadStandortAdresse', [0]), 'Standort-ID ist 0');
assertSameValue($empty, callPrivateMethod($filler, 'loadStandortAdresse', ['abc']), 'Standort-ID ist nicht numerisch');

assertSameValue(
    [
        'strasse' => 'Am Bahnhof',
        'hausnummer' => '',
        'plz' => '10115',
        'ort' => 'Berlin',
    ],
    callPrivateMethod($filler, 'loadStandortAdresse', [6]),
    'Fall 5: Strasse ohne Hausnummer'
);

assertSameValue($empty, callPrivateMethod($filler, 'loadStandortAdresse', [7]), 'Fall 6: Leere Standortfelder');

assertSameValue(['Karl-Marx-Allee', '12a'], callPrivateMethod($filler, 'splitStreetAndHouseNumber', ['Karl-Marx-Allee 12a']), 'Hausnummer mit Buchstabe');
assertSameValue(['Strasse des 17. Juni', '135'], callPrivateMethod($filler, 'splitStreetAndHouseNumber', ['Strasse des 17. Juni 135']), 'Strasse mit Zahl im Namen');
assertSameValue(['Musterweg', '12-14'], callPrivateMethod($filler, 'splitStreetAndHouseNumber', ['Musterweg 12-14']), 'Hausnummer mit Bindestrich');
assertSameValue(['Musterweg', '12 A'], callPrivateMethod($filler, 'splitStreetAndHouseNumber', ['Musterweg 12 A']), 'Hausnummer mit getrenntem Buchstaben');
assertSameValue(['Musterweg', '12 Hinterhaus'], callPrivateMethod($filler, 'splitStreetAndHouseNumber', ['Musterweg 12 Hinterhaus']), 'Hausnummer mit Zusatz');

$vermittlungMitteilungMap = callPrivateMethod($filler, 'fieldMap', ['vermittlung_mitteilung']);
$vermittlungTeilnahmeberichtMap = callPrivateMethod($filler, 'fieldMap', ['vermittlung_teilnahmebericht']);
$amdlMitteilungMap = callPrivateMethod($filler, 'fieldMap', ['amdl_mitteilung']);

assertSameValue('txtf_14_Stasse', $vermittlungMitteilungMap['standort_strasse'], 'PDF 3 Strassen-Mapping');
assertSameValue('txtf_15_Hausnummer', $vermittlungMitteilungMap['standort_hausnummer'], 'PDF 3 Hausnummer-Mapping');
assertSameValue('txtf_16_PLZ', $vermittlungMitteilungMap['standort_plz'], 'PDF 3 PLZ-Mapping');
assertSameValue('txtf_17_Ort', $vermittlungMitteilungMap['standort_ort'], 'PDF 3 Ort-Mapping');

assertSameValue('txtfMassnahmeStr', $vermittlungTeilnahmeberichtMap['standort_strasse'], 'PDF 2 Strassen-Mapping');
assertSameValue('txtfMassnahmeHausNr', $vermittlungTeilnahmeberichtMap['standort_hausnummer'], 'PDF 2 Hausnummer-Mapping');
assertSameValue('txtfMassnahmePLZ', $vermittlungTeilnahmeberichtMap['standort_plz'], 'PDF 2 PLZ-Mapping');
assertSameValue('txtfMassnahmeOrt', $vermittlungTeilnahmeberichtMap['standort_ort'], 'PDF 2 Ort-Mapping');

assertSameValue('txtf_11_Strasse', $amdlMitteilungMap['standort_strasse'], 'PDF 1 Strassen-Mapping');
assertSameValue('txtf_12_Hausnummer', $amdlMitteilungMap['standort_hausnummer'], 'PDF 1 Hausnummer-Mapping');
assertSameValue('txtf_13_PLZ', $amdlMitteilungMap['standort_plz'], 'PDF 1 PLZ-Mapping');
assertSameValue('txtf_14_Ort', $amdlMitteilungMap['standort_ort'], 'PDF 1 Ort-Mapping');

echo "PdfFormFiller Standortadresse: all tests passed\n";
