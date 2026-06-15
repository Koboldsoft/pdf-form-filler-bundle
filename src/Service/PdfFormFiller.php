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
                if (array_key_exists($fieldName, $fieldValues)) {
                    $fieldValues[$fieldName] = $values[$sourceKey] ?? '';
                }
            }
        }

        $outputPath = $this->fillWithPdftk($pdfPath, $fieldValues, $editable);

        return [
            'path' => $outputPath,
            'filename' => pathinfo(self::PDF_FILES[$pdfKey], PATHINFO_FILENAME) . '_auftrag_' . $auftragId . '.pdf',
        ];
    }

    private function loadAuftrag(int $auftragId): array
    {
        $auftrag = $this->connection->fetchAssociative(
            'SELECT vorname, nachname, code, f_massnahmenr, datum_eintritt, datum_austritt,datum_austritt_vorzeitig FROM mm_auftrag WHERE id = ?',
            [$auftragId]
        );

        if (! $auftrag) {
            throw new RuntimeException('Auftrag nicht gefunden.');
        }

        return $auftrag;
    }

    private function buildValues(array $auftrag): array
    {
        return [
            'vorname' => (string) ($auftrag['vorname'] ?? ''),
            'nachname' => (string) ($auftrag['nachname'] ?? ''),
            'gutschein' => (string) ($auftrag['code'] ?? ''),
            'massnahmetraeger' => 'digi.camp SLE GmbH',
            'strasse' => 'Boxhagener Str.',
            'hausnummer' => '77/78',
            'plz' => '102245',
            'ort' => 'Berlin',
            'strasse_hausnummer' => 'Boxhagener Str. 77/78',
            'plz_ort' => '102245 Berlin',
            'massnahmenummer' => (string) ($auftrag['f_massnahmenr'] ?? ''),
            'teilnahmebeginn' => $this->formatDate($auftrag['datum_eintritt'] ?? null),
            'teilnahmeende' => $this->formatDate($auftrag['datum_austritt'] ?? null),
            'teilnahme_austritt_vorzeitig' => $this->formatDate($auftrag['datum_austritt_vorzeitig'] ?? null),
            'massnahmebeendet' => ! empty($auftrag['datum_austritt_vorzeitig']) ? 'Die Teilnahme wurde vorzeitig beendet' : '',
            'jobcenter_teilnahme_vorzeitig_beendet' => ! empty($auftrag['datum_austritt_vorzeitig']) ? '2' : '',
            'jobcenter_letzter_teilnahmetag' => $this->formatDate(($auftrag['datum_austritt_vorzeitig'] ?? null) ?: ($auftrag['datum_austritt'] ?? null)),
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
            'strasse' => 'txtf_6_Strasse',
            'hausnummer' => 'txtf_7_Hausnummer',
            'plz' => 'txtf_8_PLZ',
            'ort' => 'txtf_9_Ort',
            'massnahmenummer' => 'txtf_10_Massnahmenummer',
            'teilnahmebeginn' => 'txtf_11_Teilnahmebeginn',
            'teilnahmeende' => 'txtf_12_Teilnahmeende',
            'teilnahme_austritt_vorzeitig' => 'txtf_18_Massnahmebeendet_am',
            'massnahmebeendet' => 'chbx_18_Massnahmebeendet',
            'unterschrift_ort' => 'txtf_19_Ort',
            'datum' => 'txtf_20_Datum',
        ];

        $maps = [
            'vermittlung_mitteilung' => $commonA,
            'vermittlung_teilnahmebericht' => [
                'vorname' => 'txtfPersonVorname',
                'nachname' => 'txtfPersonNachname',
                'massnahmetraeger' => 'txtfBetrieb',
                'strasse' => 'txtfBetriebStr',
                'hausnummer' => 'txtfBetriebHausNr',
                'plz' => 'txtfBetriebPlz',
                'ort' => 'txtfBetriebOrt',
                'massnahmenummer' => 'txtfMassnahmeNr',
                'teilnahmebeginn' => 'dateMassnahmeVon',
                'teilnahmeende' => 'dateMassnahmeBis',
                'unterschrift_ort' => 'txtfErklaerungOrt',
                'datum' => 'dateErklaerung',
            ],
            'amdl_mitteilung' => [
                'vorname' => 'txtf_1_Vorname',
                'nachname' => 'txtf_2_Nachname',
                'gutschein' => 'txtf_3_Gutschein',
                'massnahmetraeger' => 'txtf_4_Massnahmetraeger',
                'strasse' => 'txtf_5_Strasse',
                'hausnummer' => 'txtf_6_Hausnummer',
                'plz' => 'txtf_7_PLZ',
                'ort' => 'txtf_8_Ort',
                'massnahmenummer' => 'txtf_9_Massnahmenummer',
                'teilnahmebeginn' => 'txtf_15_Teilnahmebeginn',
                'teilnahmeende' => 'txtf_16_Teilnahmeende',
                'unterschrift_ort' => 'txtf_18_Ort',
                'datum' => 'txtf_19_Datum',
            ],
            'jobcenter_teilnahmebericht' => [
                'vorname' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular1[0].Vorname[0]',
                'nachname' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular1[0].Name[0]',
                'gutschein' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular1[0].KuNummer[0]',
                'massnahmetraeger' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular1[0].Maßnahmetraeger[0]',
                'strasse_hausnummer' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular1[0].Straße1[0]',
                'plz_ort' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular1[0].PLZOrt3[0]',
                'massnahmenummer' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular1[0].Maßnahmenummer[0]',
                'teilnahmebeginn' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular2[0].regulaereMaß[0]',
                'teilnahmeende' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular2[0].bis[0]',
                'jobcenter_letzter_teilnahmetag' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular2[0].letzterTag[0]',
                'jobcenter_teilnahme_vorzeitig_beendet' => 'TeilnehmerbezogenerBericht[0].Seite1[0].Teilformular2[0].nein[1]',
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

        if (! is_file($outputPath) || filesize($outputPath) === 0) {
            throw new RuntimeException('PDF konnte nicht erstellt werden.');
        }

        return $outputPath;
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
        exec($command . ' 2>&1', $output, $code);

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
