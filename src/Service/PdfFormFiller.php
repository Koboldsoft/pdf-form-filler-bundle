<?php

declare(strict_types=1);

namespace Koboldsoft\PdfFormFillerBundle\Service;

use Doctrine\DBAL\Connection;
use RuntimeException;

class PdfFormFiller
{
    private const PDF_DIR = __DIR__ . '/../Pdfs';

    private const PDF_FILES = [
        'vermittlung_mitteilung' => 'a_VorlageMitteilungZurVorlageBeiDerVermittlungsfachkraft.pdf',
        'vermittlung_teilnahmebericht' => 'b_VorlageTeilnahmebezogenerBerichtZurVorlageBeiDerVermittlungsfachkraft.pdf',
        'amdl_mitteilung' => 'c_VorlageMitteilungZurVorlagebeimOperativenServiceAMDL.pdf',
        'jobcenter_teilnahmebericht' => 'd_VorlageTeilnehmerbezogenerBerichtJobcenter.pdf',
    ];

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function fillPdf(string $pdfKey, int $auftragId, bool $editable = false): array
    {
        if (! isset(self::PDF_FILES[$pdfKey])) {
            throw new RuntimeException('Unbekannte PDF-Vorlage: ' . $pdfKey);
        }

        $pdfPath = self::PDF_DIR . '/' . self::PDF_FILES[$pdfKey];
        if (! is_file($pdfPath)) {
            throw new RuntimeException('PDF-Vorlage nicht gefunden: ' . $pdfPath);
        }

        $auftrag = $this->loadAuftrag($auftragId);
        $values = $this->buildValues($auftrag);
        $fields = $this->readFormFields($pdfPath);
        $fieldValues = array_fill_keys($fields, '');

        foreach ($this->fieldMap($pdfKey) as $sourceKey => $targetFields) {
            foreach ((array) $targetFields as $fieldName) {
                if ($fields === [] || array_key_exists($fieldName, $fieldValues)) {
                    $fieldValues[$fieldName] = $values[$sourceKey] ?? '';
                }
            }
        }

        $outputPath = $this->fillWithPdftk($pdfPath, $fieldValues, $editable);

        return [
            'path' => $outputPath,
            'filename' => $this->outputFilename($pdfKey, (string) ($auftrag['code'] ?? $auftragId)),
        ];
    }

    private function loadAuftrag(int $auftragId): array
    {
        $auftrag = $this->connection->fetchAssociative(
            'SELECT a.vorname, a.nachname, a.strasse, a.plz, a.ort, a.code, a.f_massnahmenr, a.datum_eintritt, a.datum_austritt, a.datum_austritt_vorzeitig, ag.auftraggeber, m.massnahme
             FROM mm_auftrag a
             LEFT JOIN mm_auftraggeber ag ON ag.id = a.id_auftraggeber
             LEFT JOIN mm_massnahme m ON m.id = a.id_massnahme
             WHERE a.id = ?',
            [$auftragId]
        );

        if (! $auftrag) {
            throw new RuntimeException('Auftrag nicht gefunden.');
        }

        return $auftrag;
    }

    private function buildValues(array $auftrag): array
    {
        [$teilnehmerStrasse, $teilnehmerHausnummer] = $this->splitStreetAndHouseNumber((string) ($auftrag['strasse'] ?? ''));

        return [
            'vorname' => (string) ($auftrag['vorname'] ?? ''),
            'nachname' => (string) ($auftrag['nachname'] ?? ''),
            'gutschein' => (string) ($auftrag['code'] ?? ''),
            'auftrag_code' => (string) ($auftrag['code'] ?? ''),
            'massnahmetraeger' => 'digi.camp SLE GmbH',
            'traeger_strasse' => 'Boxhagener Str.',
            'traeger_hausnummer' => '77/78',
            'traeger_strasse_hausnummer' => 'Boxhagener Str. 77/78',
            'traeger_plz' => '10245',
            'traeger_ort' => 'Berlin',
            'traeger_plz_ort' => '10245 Berlin',
            'teilnehmer_strasse' => $teilnehmerStrasse,
            'teilnehmer_hausnummer' => $teilnehmerHausnummer,
            'teilnehmer_strasse_hausnummer' => trim((string) ($auftrag['strasse'] ?? '')),
            'teilnehmer_plz' => (string) ($auftrag['plz'] ?? ''),
            'teilnehmer_ort' => (string) ($auftrag['ort'] ?? ''),
            'teilnehmer_plz_ort' => trim((string) ($auftrag['plz'] ?? '') . ' ' . (string) ($auftrag['ort'] ?? '')),
            'massnahmeort' => 'digi.camp SLE GmbH / online',
            'fehltage' => '---',
            'unentschuldigte_tage' => '---',
            'termin_nicht_erschienen' => '---',
            'massnahme_vorzeitig_beendet_am_os' => '---',
            'massnahme_vorzeitig_beendet_am_av' => '',
            'checkbox_off' => 'Off',
            'massnahmebezeichnung' => (string) ($auftrag['massnahme'] ?? ''),
            'massnahmenummer' => (string) ($auftrag['f_massnahmenr'] ?? ''),
            'teilnahmebeginn' => $this->formatDate($auftrag['datum_eintritt'] ?? null),
            'teilnahmeende' => $this->formatDate($auftrag['datum_austritt'] ?? null),
            'teilnahme_austritt_vorzeitig' => $this->formatDate($auftrag['datum_austritt_vorzeitig'] ?? null),
            'jobcenter' => (string) ($auftrag['auftraggeber'] ?? ''),
            'jobcenter_stets_anwesend' => '1',
            'jobcenter_vorzeitiges_ende_nein' => '1',
            'jobcenter_vorzeitiges_ende_ja' => 'Off',
            'jobcenter_letzter_teilnahmetag' => '',
            'siehe_anlage' => 'siehe Anlage',
            'radio_ja' => '0',
            'radio_nein' => '1',
            'radio_off' => 'Off',
            'unterschrift_ort' => 'Berlin',
            'datum' => date('d.m.Y'),
            'ort_datum' => 'Berlin, ' . date('d.m.Y'),
        ];
    }

    private function fieldMap(string $pdfKey): array
    {
        $commonA = [
            'vorname' => 'txtf_2_Vorname',
            'nachname' => 'txtf_3_Nachname',
            'gutschein' => 'txtf_4_Gutschein',
            'massnahmetraeger' => 'txtf_5_Massnahmetraeger',
            'traeger_strasse' => 'txtf_6_Strasse',
            'traeger_hausnummer' => 'txtf_7_Hausnummer',
            'traeger_plz' => 'txtf_8_PLZ',
            'traeger_ort' => 'txtf_9_Ort',
            'massnahmenummer' => 'txtf_10_Massnahmenummer',
            'teilnahmebeginn' => 'txtf_11_Teilnahmebeginn',
            'teilnahmeende' => 'txtf_12_Teilnahmeende',
            'massnahmeort' => 'txtf_13_Massnahmeort',
            'fehltage' => 'txtf_18_Fehltage',
            'unentschuldigte_tage' => 'txtf_18_Unentschuldigt',
            'termin_nicht_erschienen' => 'txtf_18_Termin',
            'massnahme_vorzeitig_beendet_am_av' => 'txtf_18_Massnahmebeendet_am',
            'checkbox_off' => [
                'chbx_18_Fehltage',
                'chbx_18_Unentschuldigt',
                'chbx_18_Termin',
                'chbx_18_Massnahmebeendet',
            ],
            'unterschrift_ort' => 'txtf_19_Ort',
            'datum' => 'txtf_20_Datum',
        ];

        $maps = [
            'vermittlung_mitteilung' => $commonA,
            'vermittlung_teilnahmebericht' => [
                'vorname' => 'txtfPersonVorname',
                'nachname' => 'txtfPersonNachname',
                'teilnehmer_strasse' => 'txtfPersonStr',
                'teilnehmer_hausnummer' => 'txtfPersonHausNr',
                'teilnehmer_plz' => 'txtfPersonPlz',
                'teilnehmer_ort' => 'txtfPersonOrt',
                'massnahmetraeger' => 'txtfBetrieb',
                'traeger_strasse' => 'txtfBetriebStr',
                'traeger_hausnummer' => 'txtfBetriebHausNr',
                'traeger_plz' => 'txtfBetriebPlz',
                'traeger_ort' => 'txtfBetriebOrt',
                'massnahmeort' => 'txtfMassnahmeDurchfuehrungt',
                'massnahmenummer' => 'txtfMassnahmeNr',
                'massnahmebezeichnung' => 'txtfMassnahme',
                'teilnahmebeginn' => 'dateMassnahmeVon',
                'teilnahmeende' => 'dateMassnahmeBis',
                'radio_nein' => 'rbtnEinschaetzungVermittelt',
                'radio_off' => 'rbtnBeschaeftigungVerhaeltnis',
                'siehe_anlage' => [
                    'txtareaEinschaetzungKenntinsse',
                    'txtareaEinschaetzungInteresse',
                    'txtareaEinschaetzungIntegration',
                ],
                'unterschrift_ort' => 'txtfErklaerungOrt',
                'datum' => 'dateErklaerung',
            ],
            'amdl_mitteilung' => [
                'vorname' => 'txtf_1_Vorname',
                'nachname' => 'txtf_2_Nachname',
                'gutschein' => 'txtf_3_Gutschein',
                'massnahmetraeger' => 'txtf_4_Massnahmetraeger',
                'traeger_strasse' => 'txtf_5_Strasse',
                'traeger_hausnummer' => 'txtf_6_Hausnummer',
                'traeger_plz' => 'txtf_7_PLZ',
                'traeger_ort' => 'txtf_8_Ort',
                'massnahmenummer' => 'txtf_9_Massnahmenummer',
                'massnahmeort' => 'txtf_10_Massnahmeort',
                'fehltage' => 'txtf_17_Fehltage',
                'unentschuldigte_tage' => 'txtf_17_Unentschuldigt',
                'massnahme_vorzeitig_beendet_am_os' => 'txtf_17_Beendet_am',
                'checkbox_off' => [
                    'chbx_17_Fehltage',
                    'chbx_17_Unetnschuldigt',
                    'chbx_17_Beendet_am',
                ],
                'teilnahmebeginn' => 'txtf_15_Teilnahmebeginn',
                'teilnahmeende' => 'txtf_16_Teilnahmeende',
                'unterschrift_ort' => 'txtf_18_Ort',
                'datum' => 'txtf_19_Datum',
            ],
            'jobcenter_teilnahmebericht' => [
                'jobcenter' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Jobcenter[0]',
                'vorname' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular1[0].Vorname[0]',
                'nachname' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular1[0].Name[0]',
                'gutschein' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular1[0].KuNummer[0]',
                'massnahmetraeger' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular1[0].Maßnahmetraeger[0]',
                'teilnehmer_strasse_hausnummer' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular1[0].Straße[0]',
                'teilnehmer_plz_ort' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular1[0].PLZOrt1[0]',
                'traeger_strasse_hausnummer' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular1[0].Straße1[0]',
                'traeger_plz_ort' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular1[0].PLZOrt3[0]',
                'massnahmenummer' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular1[0].Maßnahmenummer[0]',
                'teilnahmebeginn' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular2[0].regulaereMaß[0]',
                'teilnahmeende' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular2[0].bis[0]',
                'jobcenter_stets_anwesend' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular2[0].Abwesenheit1[0]',
                'jobcenter_vorzeitiges_ende_nein' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular2[0].nein[0]',
                'jobcenter_vorzeitiges_ende_ja' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular2[0].nein[1]',
                'jobcenter_letzter_teilnahmetag' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular2[0].letzterTag[0]',
                'siehe_anlage' => [
                    'TeilnehmerbezogenerBericht[0].Seite2[0].Teilformular3[0].KenntnisseundFaehigkeiten[0]',
                    'TeilnehmerbezogenerBericht[0].Seite2[0].Teilformular3[0].PersoenlicheEigenschaften[0]',
                ],
                'ort_datum' => 'TeilnehmerbezogenerBericht[0].Seite2[0].Teilformular4[0].OrtDatum[0]',
            ],
        ];

        return $maps[$pdfKey];
    }

    private function readFormFields(string $pdfPath): array
    {
        $output = $this->runCommand(sprintf('pdftk %s dump_data_fields_utf8', escapeshellarg($pdfPath)));
        preg_match_all('/^FieldName: (.+)$/m', $output, $matches);

        return $matches[1] ?? [];
    }

    private function outputFilename(string $pdfKey, string $code): string
    {
        $suffix = $this->sanitizeFilenamePart($code);

        $prefixes = [
            'vermittlung_mitteilung' => 'Anlage AV',
            'vermittlung_teilnahmebericht' => 'Teilnehmerbezogner Bericht',
            'amdl_mitteilung' => 'Anlage OS',
            'jobcenter_teilnahmebericht' => 'Teilnehmerbezogner Bericht',
        ];

        return ($prefixes[$pdfKey] ?? pathinfo(self::PDF_FILES[$pdfKey], PATHINFO_FILENAME)) . '_' . $suffix . '.pdf';
    }

    private function sanitizeFilenamePart(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[^\pL\pN._-]+/u', '-', $value) ?? '';
        $value = trim($value, '.-_');

        return $value !== '' ? $value : 'auftrag';
    }

    private function splitStreetAndHouseNumber(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return ['', ''];
        }

        if (preg_match('/^(.+?)\s+(\d+\s*[a-zA-Z]?(?:\s*[-\/]\s*\d+\s*[a-zA-Z]?)?)$/u', $value, $matches)) {
            return [trim($matches[1]), preg_replace('/\s+/', '', trim($matches[2]))];
        }

        return [$value, ''];
    }

    private function fillWithPdftk(string $pdfPath, array $fieldValues, bool $editable): string
    {
        $workDir = sys_get_temp_dir() . '/pdf_form_filler_' . bin2hex(random_bytes(8));
        if (! mkdir($workDir, 0777, true) && ! is_dir($workDir)) {
            throw new RuntimeException('Temp-Ordner konnte nicht erstellt werden: ' . $workDir);
        }

        $xfdfPath = $workDir . '/data.xfdf';
        $outputPath = $workDir . '/filled.pdf';
        file_put_contents($xfdfPath, $this->buildXfdf($fieldValues));

        $pdftkOptions = $editable ? 'need_appearances' : 'flatten';

        $this->runCommand(sprintf(
            'pdftk %s fill_form %s output %s %s',
            escapeshellarg($pdfPath),
            escapeshellarg($xfdfPath),
            escapeshellarg($outputPath),
            $pdftkOptions
        ));

        if ($editable) {
            $outputPath = $this->prepareEditablePdfAnnotations($outputPath, $fieldValues, $workDir);
        }

        if (! is_file($outputPath) || filesize($outputPath) === 0) {
            throw new RuntimeException('PDF konnte nicht erstellt werden.');
        }

        return $outputPath;
    }

    private function prepareEditablePdfAnnotations(string $pdfPath, array $fieldValues, string $workDir): string
    {
        $radioOptions = [
            'rbtnEinschaetzungVermittelt' => ['0', '1'],
            'rbtnBeschaeftigungVerhaeltnis' => ['0', '1'],
        ];

        $qdfPath = $workDir . '/editable_annotations.qdf.pdf';
        $fixedQdfPath = $workDir . '/editable_annotations_fixed.qdf.pdf';
        $repairedQdfPath = $workDir . '/editable_annotations_repaired.qdf.pdf';
        $fixedPdfPath = $workDir . '/filled_editable_fixed.pdf';

        $this->runCommand(sprintf(
            'qpdf --qdf --object-streams=disable %s %s',
            escapeshellarg($pdfPath),
            escapeshellarg($qdfPath)
        ));

        $qdf = file_get_contents($qdfPath);
        if ($qdf === false) {
            throw new RuntimeException('PDF konnte nicht zur Radio-Korrektur gelesen werden.');
        }

        foreach ($radioOptions as $fieldName => $options) {
            $qdf = $this->syncRadioFieldInQdf($qdf, $fieldName, (string) ($fieldValues[$fieldName] ?? 'Off'), $options);
        }

        $qdf = $this->moveStampAnnotationsBehindOtherAnnotations($qdf);

        file_put_contents($fixedQdfPath, $qdf);

        $this->runCommand(sprintf(
            'fix-qdf %s > %s',
            escapeshellarg($fixedQdfPath),
            escapeshellarg($repairedQdfPath)
        ));

        $this->runCommand(sprintf(
            'qpdf %s %s',
            escapeshellarg($repairedQdfPath),
            escapeshellarg($fixedPdfPath)
        ));

        return $fixedPdfPath;
    }

    private function moveStampAnnotationsBehindOtherAnnotations(string $qdf): string
    {
        preg_match_all('/(\d+)\s+0\s+obj\s*<<(.*?)>>\s*endobj/s', $qdf, $objects, PREG_SET_ORDER);

        $stampObjectIds = [];
        foreach ($objects as $object) {
            if (strpos($object[2], '/Subtype /Stamp') !== false) {
                $stampObjectIds[] = $object[1];
            }
        }

        if ($stampObjectIds === []) {
            return $qdf;
        }

        $stampLookup = array_fill_keys($stampObjectIds, true);
        foreach ($objects as $object) {
            if (strpos($object[2], '/Annots') === false) {
                continue;
            }

            $oldObject = $object[0];
            $newBody = preg_replace_callback(
                '/\/Annots\s*\[(.*?)\]/s',
                function (array $matches) use ($stampLookup): string {
                    preg_match_all('/(\d+)\s+0\s+R/', $matches[1], $refMatches);
                    $refs = $refMatches[1] ?? [];

                    $stampRefs = [];
                    $otherRefs = [];
                    foreach ($refs as $ref) {
                        if (isset($stampLookup[$ref])) {
                            $stampRefs[] = $ref . ' 0 R';
                        } else {
                            $otherRefs[] = $ref . ' 0 R';
                        }
                    }

                    if ($stampRefs === []) {
                        return $matches[0];
                    }

                    return '/Annots [ ' . implode(' ', array_merge($stampRefs, $otherRefs)) . ' ]';
                },
                $object[2]
            );

            if ($newBody === null || $newBody === $object[2]) {
                continue;
            }

            $newObject = $object[1] . " 0 obj\n<<" . $newBody . ">>\nendobj";
            $qdf = str_replace($oldObject, $newObject, $qdf);
        }

        return $qdf;
    }

    private function syncRadioFieldInQdf(string $qdf, string $fieldName, string $value, array $options): string
    {
        preg_match_all('/(\d+)\s+0\s+obj\s*<<(.*?)>>\s*endobj/s', $qdf, $objects, PREG_SET_ORDER);

        $objectBodies = [];
        $parentBody = null;
        foreach ($objects as $object) {
            $objectBodies[$object[1]] = $object;
            if (strpos($object[2], '/T (' . $fieldName . ')') !== false && strpos($object[2], '/Kids') !== false) {
                $parentBody = $object[2];
            }
        }

        if ($parentBody === null || ! preg_match('/\/Kids\s*\[(.*?)\]/s', $parentBody, $kidsMatch)) {
            return $qdf;
        }

        preg_match_all('/(\d+)\s+0\s+R/', $kidsMatch[1], $kidMatches);
        $kidIds = $kidMatches[1] ?? [];
        usort($kidIds, function (string $left, string $right) use ($objectBodies): int {
            return $this->radioWidgetXPosition($objectBodies[$left][2] ?? '') <=> $this->radioWidgetXPosition($objectBodies[$right][2] ?? '');
        });

        foreach ($kidIds as $index => $kidId) {
            if (! isset($objectBodies[$kidId])) {
                continue;
            }

            $optionValue = $options[$index] ?? 'Off';
            $appearanceState = $value === $optionValue ? $optionValue : 'Off';
            $oldObject = $objectBodies[$kidId][0];
            $replacementCount = 0;
            $newBody = preg_replace('/\/AS\s+\/[^\s\]<>\/]+/', '/AS /' . $appearanceState, $objectBodies[$kidId][2], 1, $replacementCount);

            if ($newBody === null) {
                continue;
            }

            if ($replacementCount === 0) {
                $newBody = "\n  /AS /" . $appearanceState . $objectBodies[$kidId][2];
            }

            $newObject = $kidId . " 0 obj\n<<" . $newBody . ">>\nendobj";
            $qdf = str_replace($oldObject, $newObject, $qdf);
        }

        return $qdf;
    }

    private function radioWidgetXPosition(string $objectBody): float
    {
        if (preg_match('/\/Rect\s*\[\s*([-0-9.]+)/s', $objectBody, $matches)) {
            return (float) $matches[1];
        }

        return 0.0;
    }

    private function buildXfdf(array $fieldValues): string
    {
        $fields = '';
        foreach ($fieldValues as $name => $value) {
            $fields .= sprintf(
                '<field name="%s"><value>%s</value></field>',
                htmlspecialchars((string) $name, ENT_XML1 | ENT_COMPAT, 'UTF-8'),
                htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8')
            );
        }

        return '<?xml version="1.0" encoding="UTF-8"?><xfdf xmlns="http://ns.adobe.com/xfdf/" xml:space="preserve"><fields>' . $fields . '</fields></xfdf>';
    }

    private function runCommand(string $command): string
    {
        $output = [];
        $code = 0;

        // Pfad zur Bibliothek UND strenge Speicherlimits für Java setzen //
        $javaEnv = 'JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64 LD_LIBRARY_PATH=/usr/lib/jvm/java-17-openjdk-amd64/lib/ JAVA_TOOL_OPTIONS="-XX:CompressedClassSpaceSize=128m -Xmx128m -Djava.awt.headless=true" ';


        // exec($command . ' 2>&1', $output, $code);

        exec($javaEnv . $command . ' 2>&1', $output, $code);

        if ($code !== 0) {
            throw new RuntimeException("pdftk Fehler:\n" . implode("\n", $output));
        }

        return implode("\n", $output);
    }

    private function formatDate($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_numeric($value)) {
            return date('d.m.Y', (int) $value);
        }

        try {
            return (new \DateTimeImmutable((string) $value))->format('d.m.Y');
        } catch (\Exception $e) {
            return (string) $value;
        }
    }
}
