<?php

namespace App\Support\Reports;

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportService
{
    public const MAX_EXPORT_ROWS = 5000;

    /** @param array<string, string> $filterLabels */
    public function download(ReportDataset $dataset, string $tenantName, string $format, array $filterLabels = []): StreamedResponse
    {
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 404);
        $extension = $format === 'xlsx' ? 'xlsx' : 'pdf';
        $filename = sprintf('kostkita-%s-%s-%s.%s', $dataset->key, $dataset->period['from'], $dataset->period['to'], $extension);

        if ($format === 'xlsx') {
            return $this->spreadsheet($dataset, $tenantName, $filename, $filterLabels);
        }

        return $this->pdf($dataset, $tenantName, $filename, $filterLabels);
    }

    /** @param array<string, string> $filterLabels */
    private function spreadsheet(ReportDataset $dataset, string $tenantName, string $filename, array $filterLabels): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Laporan');
        $sheet->setCellValue('A1', 'KostKita · '.$dataset->title);
        $sheet->setCellValue('A2', $tenantName.' · Periode '.$dataset->period['from'].' sampai '.$dataset->period['to'].' · Dibuat '.$dataset->generatedAt);
        $sheet->getCell('A3')->setValueExplicit('Filter aktif: '.$this->filterText($dataset, $filterLabels), DataType::TYPE_STRING);

        $headerRow = 4;
        foreach ($dataset->columns as $index => $column) {
            $cell = $sheet->getCell([$index + 1, $headerRow]);
            $cell->setValueExplicit($column['label'], DataType::TYPE_STRING);
        }
        $lastColumn = $sheet->getCell([max(1, count($dataset->columns)), $headerRow])->getColumn();
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '172554']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->freezePane('A5');
        $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$headerRow}");

        foreach ($dataset->rows as $rowIndex => $row) {
            $excelRow = $headerRow + $rowIndex + 1;
            foreach ($dataset->columns as $columnIndex => $column) {
                $value = $row[$column['key']] ?? null;
                $cell = $sheet->getCell([$columnIndex + 1, $excelRow]);
                if (in_array($column['type'] ?? 'text', ['money', 'number'], true) && is_numeric($value)) {
                    $cell->setValue((int) $value);
                    if ($column['type'] === 'money') {
                        $cell->getStyle()->getNumberFormat()->setFormatCode('#,##0;[Red]-#,##0');
                    }
                } else {
                    $cell->setValueExplicit($value === null ? '' : (string) $value, DataType::TYPE_STRING);
                }
            }
        }

        $summaryRow = $headerRow + count($dataset->rows) + 2;
        foreach ($dataset->summary as $key => $value) {
            $sheet->setCellValueExplicit('A'.$summaryRow, $dataset->summaryLabels[$key] ?? $key, DataType::TYPE_STRING);
            if (is_int($value)) {
                $sheet->setCellValue('B'.$summaryRow, $value);
                $sheet->getStyle('B'.$summaryRow)->getNumberFormat()->setFormatCode('#,##0;[Red]-#,##0');
            } else {
                $sheet->setCellValueExplicit('B'.$summaryRow, (string) $value, DataType::TYPE_STRING);
            }
            $summaryRow++;
        }
        foreach (range(1, max(1, count($dataset->columns))) as $columnIndex) {
            $sheet->getColumnDimension($sheet->getCell([$columnIndex, 1])->getColumn())->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @param array<string, string> $filterLabels */
    private function pdf(ReportDataset $dataset, string $tenantName, string $filename, array $filterLabels): StreamedResponse
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('exports.report-pdf', [
            'dataset' => $dataset,
            'tenantName' => $tenantName,
            'filterText' => $this->filterText($dataset, $filterLabels),
        ])->render(), 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();
        $output = $pdf->output();

        return response()->streamDownload(static function () use ($output): void {
            echo $output;
        }, $filename, ['Content-Type' => 'application/pdf']);
    }

    /** @param array<string, string> $filterLabels */
    private function filterText(ReportDataset $dataset, array $filterLabels): string
    {
        $keyLabels = [
            'property_id' => 'Properti',
            'room_id' => 'Kamar',
            'from' => 'Dari',
            'to' => 'Sampai',
            'type' => 'Jenis',
            'status' => 'Status',
        ];
        $valueLabels = [
            'type' => [
                'rent_receipt' => 'Penerimaan sewa', 'deposit_receipt' => 'Penerimaan deposit jaminan',
                'temporary_liability' => 'Liability sementara', 'rent_refund' => 'Refund sewa',
                'deposit_refund' => 'Refund deposit jaminan', 'temporary_liability_refund' => 'Refund liability sementara',
                'maintenance_expense' => 'Biaya maintenance', 'operating_expense' => 'Biaya operasional',
            ],
            'status' => [
                'pending' => 'Menunggu', 'confirmed' => 'Dikonfirmasi', 'checked_in' => 'Sudah check-in',
                'cancelled' => 'Dibatalkan', 'no_show' => 'Tidak datang', 'unpaid' => 'Belum dibayar',
                'partial' => 'Dibayar sebagian', 'paid' => 'Lunas', 'overdue' => 'Terlambat',
                'scheduled' => 'Terjadwal', 'active' => 'Aktif', 'completed' => 'Selesai',
                'terminated' => 'Dihentikan', 'reported' => 'Dilaporkan',
            ],
        ];
        $filters = [];
        foreach ($dataset->filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $keyLabel = $keyLabels[$key] ?? $key;
            $valueLabel = $filterLabels[$key] ?? $valueLabels[$key][$value] ?? (string) $value;
            $filters[] = $keyLabel.': '.$valueLabel;
        }

        return $filters === [] ? '—' : implode(' · ', $filters);
    }
}
