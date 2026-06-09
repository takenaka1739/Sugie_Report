import React from 'react';
import { Button } from '@mui/material';

type MonthlyRow = { date: string; name: string };
type RemainRow  = { id: number; name: string; granted: number; used: number; remain: number };

interface Props {
  ymLabel: string;                   // 例: '2025-02'
  monthlyList: MonthlyRow[];         // 当月の有給取得者一覧
  remainList: RemainRow[];           // 年度の残有給一覧
}

/** HTMLエスケープ（シンプル版） */
const esc = (s: any) =>
  String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

const buildTable = (headers: string[], rows: (string | number)[][]) => {
  const thead = `<thead><tr>${headers.map(h => `<th>${esc(h)}</th>`).join('')}</tr></thead>`;
  const tbody = `<tbody>${rows
    .map(r => `<tr>${r.map(c => `<td>${esc(c)}</td>`).join('')}</tr>`)
    .join('')}</tbody>`;
  return `<table>${thead}${tbody}</table>`;
};

const PaidLeaveXlsButton: React.FC<Props> = ({ ymLabel, monthlyList, remainList }) => {
  const handleExport = () => {
    // シート相当（Excel互換HTMLなので1ファイル1シート。2テーブルを縦に並べます）
    const s1Header = ['取得日', '氏名'];
    const s1Rows   = monthlyList.map(r => [r.date, r.name]);

    const s2Header = ['ID', '氏名', '付与', '使用済', '残（日）'];
    const s2Rows   = remainList.map(r => [r.id, r.name, r.granted, r.used, r.remain]);

    const section1 = `<h3>当月の有給取得者一覧（${esc(ymLabel)}）</h3>${buildTable(s1Header, s1Rows)}`;
    const section2 = `<h3>社員一覧と残有給（年度単位）</h3>${buildTable(s2Header, s2Rows)}`;

    // マスタと同じ .xls（Excel互換HTML）。UTF-8 + BOM で文字化け対策
    const html = `<!DOCTYPE html>
                  <html xmlns:o="urn:schemas-microsoft-com:office:office"
                        xmlns:x="urn:schemas-microsoft-com:office:excel"
                        xmlns="http://www.w3.org/TR/REC-html40">
                  <head>
                  <meta charset="UTF-8" />
                  <!--[if gte mso 9]><xml>
                    <x:ExcelWorkbook>
                      <x:ExcelWorksheets>
                        <x:ExcelWorksheet>
                          <x:Name>有給</x:Name>
                          <x:WorksheetOptions><x:DefaultRowHeight>285</x:DefaultRowHeight></x:WorksheetOptions>
                        </x:ExcelWorksheet>
                      </x:ExcelWorksheets>
                    </x:ExcelWorkbook>
                  </xml><![endif]-->
                  <style>
                    table { border-collapse: collapse; }
                    th, td { border: 1px solid #000; padding: 4px; font-family: "MS PGothic", Arial, sans-serif; font-size: 12pt; }
                    h3 { margin: 12px 0 4px; }
                  </style>
                  </head>
                  <body>
                  ${section1}
                  <br/>
                  ${section2}
                  </body>
                  </html>`;

    const blob = new Blob(
      ['\uFEFF', html], // BOMで日本語の文字化け防止
      { type: 'application/vnd.ms-excel;charset=utf-8;' }
    );

    const fileName = `有給_${ymLabel}.xls`;
    // IE対応（必要なら）
    // @ts-ignore
    if (window.navigator && window.navigator.msSaveOrOpenBlob) {
      // @ts-ignore
      window.navigator.msSaveOrOpenBlob(blob, fileName);
      return;
    }
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = fileName;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => {
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    }, 0);
  };

  return (
    <Button variant="outlined" onClick={handleExport}>
      XLS出力
    </Button>
  );
};

export default PaidLeaveXlsButton;
