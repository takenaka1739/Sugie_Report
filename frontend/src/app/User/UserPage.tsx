//import React, { useState, useEffect } from 'react';             // React
import React, { useEffect } from 'react';                       // React
import {
  Paper,
  Button,
  TextField
} from '@mui/material';                                         // Material UI
import {
  GridRowsProp,
  GridRowModesModel,
  GridRowModes,
  DataGrid,
  GridColDef,
  GridRenderEditCellParams,
  GridRowModel,
  GridEventListener,
  GridToolbarContainer,
  GridActionsCellItem,
  GridRowEditStopReasons,
  GridRowId,
  GridSlotProps
} from '@mui/x-data-grid';                                      // Material UI
import { randomId } from '@mui/x-data-grid-generator';          // Material UI
import AddIcon from '@mui/icons-material/Add';                  // Material UI アイコン
import EditIcon from '@mui/icons-material/Edit';                // Material UI アイコン
import DeleteIcon from '@mui/icons-material/DeleteOutlined';    // Material UI アイコン
import SaveIcon from '@mui/icons-material/Save';                // Material UI アイコン
import CancelIcon from '@mui/icons-material/Close';             // Material UI アイコン
import FileDownloadIcon from '@mui/icons-material/FileDownload';// 追加：XLS出力アイコン
import axios from 'axios';                                      // HTTPリクエスト
import RestartAltIcon from '@mui/icons-material/RestartAlt';

/**
 * === APIベースURLの決定ロジック ===
 * 1) .env を最優先（REACT_APP_API_BASE）
 * 2) .env 未設定時のフォールバック
 *    - localhost系 → http://localhost/Report/backend
 *    - それ以外    → {現在のホスト}/report/backend  （本番 sugie-k.com を想定）
 * ※ 末尾スラッシュは除去（URL結合時の // 防止）
 */
const BASE_ADDR = (
  process.env.REACT_APP_API_BASE ||
  (typeof window !== 'undefined' && (
    window.location.hostname === 'localhost' ||
    window.location.hostname === '127.0.0.1'
  )
    ? 'http://localhost/Report/backend'
    : `${window.location.origin}/report/backend`)
).replace(/\/+$/, '');

// 初期表示項目(ページ/項目数)
const paginationModel = { page: 1, pageSize: 10 };

// TypeScript用設定：ツールバーに baseAddr を渡せるよう拡張
declare module '@mui/x-data-grid' {
  interface ToolbarPropsOverrides {
    setRows: (newRows: (oldRows: GridRowsProp) => GridRowsProp) => void;
    setRowModesModel: (
      newModel: (oldModel: GridRowModesModel) => GridRowModesModel,
    ) => void;
    baseAddr: string;
  }
}

// ツールバー
const EditToolbar = (props: GridSlotProps['toolbar']) => {
  const { setRows, setRowModesModel, baseAddr } = props;

  const handleClick = () => {
    const id = randomId();
    setRows((oldRows) => [
      ...oldRows,
      {
        id,
        name: '',
        is_authorized: 0,
        nationality_id: 0,
        shift_id: 0,
        blood_type_id: 0,
        health_checked_on: '',
        paid_holidays_num: 0,
        retiremented_on: '',
        work_cloth_1_id: 0,
        work_cloth_2_id: 0,
        work_cloth_3_id: 0,
        work_cloth_4_id: 0,
        work_cloth_5_id: 0,
        work_cloth_6_id: 0,
        work_cloth_7_id: 0,
        isNew: true
      },
    ]);
    setRowModesModel((oldModel) => ({
      ...oldModel,
      [id]: { mode: GridRowModes.Edit, fieldToFocus: 'name' },
    }));
  };

  // エクセル出力
  const handleExportXls = () => {
    const url = `${baseAddr}/m_users/export_xls.php`;
    window.open(url, '_blank', 'noopener,noreferrer');
  };

  return (
    <GridToolbarContainer>
      {/* エクセル出力（統一デザイン） */}
      <Button
        size="small"
        variant="contained"
        startIcon={<FileDownloadIcon />}
        onClick={handleExportXls}
        sx={{ mr: 1 }}
      >
        エクセル出力
      </Button>
      <Button color="primary" startIcon={<AddIcon />} onClick={handleClick}>
        追加
      </Button>
    </GridToolbarContainer>
  );
};

// 初期値
const initialRows: GridRowsProp = [];

// デートピッカー
const DatePickerCell = (params: GridRenderEditCellParams) => {
  const { id, field, value, api } = params;

  const handleChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    api.setEditCellValue({ id, field, value: event.target.value });
  };

  return (
    <TextField
      type="date"
      value={value || ""}
      onChange={handleChange}
      fullWidth
    />
  );
};

// 作業着のサイズ
const clothSize = [
  {label: 'ー', value: 0},
  {label: 'S', value: 1},
  {label: 'M', value: 2},
  {label: 'L', value: 3},
  {label: '2L', value: 4},
  {label: '3L', value: 5},
  {label: '4L', value: 6},
  {label: '5L', value: 7},
  {label: '特注', value: 8}
];

const pantshSize = [
  {label: 'ー', value: 0},
  {label: '70', value: 1},
  {label: '73', value: 2},
  {label: '76', value: 3},
  {label: '79', value: 4},
  {label: '82', value: 5},
  {label: '85', value: 6},
  {label: '88', value: 7},
  {label: '91', value: 8},
  {label: '95', value: 9},
  {label: '100', value: 10},
  {label: '105', value: 11},
  {label: '110', value: 12},
  {label: '120', value: 13}
];

// 国籍
const nationality = [
  {label: 'ー', value: 0},
  {label: '日本人', value: 1},
  {label: '外国人', value: 2}
];

// 血液型
const bloodType = [
  {label: 'ー', value: 0},
  {label: 'A型', value: 1},
  {label: 'B型', value: 2},
  {label: 'AB型', value: 3},
  {label: 'O型', value: 4}
];

interface ShiftItem {
  label: string;
  value: number;
}

// 社員マスタページ
const UserPage: React.FC = () => {
  const [rows, setRows] = React.useState<GridRowsProp>(initialRows);
  const [rowModesModel, setRowModesModel] = React.useState<GridRowModesModel>({});
  const [shift, setShift] = React.useState<ShiftItem[]>([{ label: '未設定', value: 0 }]);

  // DBデータの取得
  useEffect(() => {
    const fetchData = async () => {
      try {
        const user_response = await axios.get<GridRowsProp>(`${BASE_ADDR}/m_users/select.php`, {
          headers: { 'Accept': 'application/json' },
        });
        setRows(user_response.data);

        const shift_response = await axios.get<any[]>(`${BASE_ADDR}/m_shifts/select.php`, {
          headers: { 'Accept': 'application/json' },
        });

        const options: ShiftItem[] = [{ label: '未設定', value: 0 }];
        shift_response.data.forEach((row: any) => {
          const rs = (row.regular_start || '').split(':').slice(0, 2).join(':');
          const rf = (row.regular_finish || '').split(':').slice(0, 2).join(':');
          const os = (row.overtime_start || '').split(':').slice(0, 2).join(':');
          const ls = (row.late_overtime_start || '').split(':').slice(0, 2).join(':');
          options.push({
            label: `定時：${rs}～${rf}　残業：${os}～　深夜：${ls}～`,
            value: row.id,
          });
        });
        setShift(options);
      } catch (err: any) {
        if (err.response) {
          alert(`一覧取得に失敗しました (HTTP ${err.response.status})。バックエンドのログ(PHP)も確認してください。`);
        } else if (err.request) {
          alert('一覧取得に失敗しました。サーバ到達不可の可能性（URL/ポート、CORS、Apache起動状態）を確認してください。');
        } else {
          alert(`一覧取得に失敗しました: ${err.message}`);
        }
      }
    };

    fetchData();
  }, []);

  // 編集モード中に別操作の実施イベント
  const handleRowEditStop: GridEventListener<'rowEditStop'> = (params, event) => {
    if (params.reason === GridRowEditStopReasons.rowFocusOut) {
      event.defaultMuiPrevented = true;
    }
  };

  // 編集ボタンクリックイベント
  const handleEditClick = (id: GridRowId) => () => {
    setRowModesModel({ ...rowModesModel, [id]: { mode: GridRowModes.Edit } });
  };

  // 保存ボタンクリックイベント
  const handleSaveClick = (id: GridRowId) => () => {
    setRowModesModel({ ...rowModesModel, [id]: { mode: GridRowModes.View } });
  };

  // 削除ボタンクリックイベント
  const handleDeleteClick = (id: GridRowId) => () => {
    setRows(rows.filter((row) => row.id !== id));

    if (typeof id === 'number') {
      axios.post(`${BASE_ADDR}/m_users/delete.php`, { id }).catch(() => {
        alert('削除APIでエラーが発生しました。');
      });
    }
  };

  const handlePasswordResetClick = (id: GridRowId) => async () => {

    if (!window.confirm('パスワードを初期値(password)にリセットしますか？')) {
      return;
    }

    try {

      await axios.post(`${BASE_ADDR}/m_users/reset_password.php`, {
        id
      });

      alert('パスワードを初期値(password)にリセットしました');

    } catch {

      alert('パスワードリセットでエラーが発生しました');

    }

  };

  // 編集キャンセルボタンクリックイベント
  const handleCancelClick = (id: GridRowId) => () => {
    setRowModesModel({
      ...rowModesModel,
      [id]: { mode: GridRowModes.View, ignoreModifications: true },
    });

    const editedRow = rows.find((row) => row.id === id);
    if (editedRow && (editedRow as any).isNew) {
      setRows(rows.filter((row) => row.id !== id));
    }
  };

  // 編集完了イベント
  const processRowUpdate = async (newRow: GridRowModel) => {
    const updatedRow = { ...newRow, isNew: false };
    setRows(rows.map((row) => (row.id === newRow.id ? updatedRow : row)));

    if (newRow.name === '') {
      window.alert('氏名が未入力のため登録できません。');
      return updatedRow;
    }

    try {
      if ((newRow as any).isNew || isNaN(newRow.id as any)) {
        await axios.post(`${BASE_ADDR}/m_users/insert.php`, {
          name: newRow.name,
          is_authorized: newRow.is_authorized,
          nationality_id: newRow.nationality_id,
          shift_id: newRow.shift_id,
          blood_type_id: newRow.blood_type_id,
          health_checked_on: (newRow.health_checked_on === '') ? null : newRow.health_checked_on,
          paid_holidays_num: newRow.paid_holidays_num,
          retiremented_on: (newRow.retiremented_on === '') ? null : newRow.retiremented_on,
          work_cloth_1_id: newRow.work_cloth_1_id,
          work_cloth_2_id: newRow.work_cloth_2_id,
          work_cloth_3_id: newRow.work_cloth_3_id,
          work_cloth_4_id: newRow.work_cloth_4_id,
          work_cloth_5_id: newRow.work_cloth_5_id,
          work_cloth_6_id: newRow.work_cloth_6_id,
          work_cloth_7_id: newRow.work_cloth_7_id
        });
      } else {
        await axios.post(`${BASE_ADDR}/m_users/update.php`, {
          id: newRow.id,
          name: newRow.name,
          is_authorized: newRow.is_authorized,
          nationality_id: newRow.nationality_id,
          shift_id: newRow.shift_id,
          blood_type_id: newRow.blood_type_id,
          health_checked_on: (newRow.health_checked_on === '') ? null : newRow.health_checked_on,
          paid_holidays_num: newRow.paid_holidays_num,
          retiremented_on: (newRow.retiremented_on === '') ? null : newRow.retiremented_on,
          work_cloth_1_id: newRow.work_cloth_1_id,
          work_cloth_2_id: newRow.work_cloth_2_id,
          work_cloth_3_id: newRow.work_cloth_3_id,
          work_cloth_4_id: newRow.work_cloth_4_id,
          work_cloth_5_id: newRow.work_cloth_5_id,
          work_cloth_6_id: newRow.work_cloth_6_id,
          work_cloth_7_id: newRow.work_cloth_7_id
        });
      }

      const user_response = await axios.get<GridRowsProp>(`${BASE_ADDR}/m_users/select.php`, {
        headers: { 'Accept': 'application/json' },
      });
      setRows(user_response.data);
    } catch {
      alert('登録/更新APIでエラーが発生しました。バックエンドのログを確認してください。');
    }

    return updatedRow;
  };

  const handleRowModesModelChange = (newRowModesModel: GridRowModesModel) => {
    setRowModesModel(newRowModesModel);
  };

  // DataGridの列項目
  const columns: GridColDef[] = [
    { field: 'name', headerName: '氏名', width: 130, editable: true },
    { field: 'is_authorized', headerName: '管理者', width: 80, editable: true, type: 'boolean' },
    { field: 'nationality_id', headerName: '区分', width: 80, editable: true, type: 'singleSelect', valueOptions: nationality },
    { field: 'shift_id', headerName: 'シフト設定', width: 390, editable: true, type: 'singleSelect', valueOptions: shift },
    { field: 'blood_type_id', headerName: '血液型', width: 70, editable: true, type: 'singleSelect', valueOptions: bloodType },
    { field: 'health_checked_on', headerName: '健康診断日', width: 100, editable: true, renderEditCell: (params) => <DatePickerCell {...params} /> },
    { field: 'paid_holidays_num', headerName: '有休日数', width: 80, editable: true },
    { field: 'retiremented_on', headerName: '退職日', width: 100, editable: true, renderEditCell: (params) => <DatePickerCell {...params} /> },
    { field: 'work_cloth_1_id', headerName: '(夏)ブルゾン', width: 100, editable: true, type: 'singleSelect', valueOptions: clothSize },
    { field: 'work_cloth_2_id', headerName: '(夏)パンツ', width: 100, editable: true, type: 'singleSelect', valueOptions: pantshSize },
    { field: 'work_cloth_3_id', headerName: '(夏)空調服', width: 100, editable: true, type: 'singleSelect', valueOptions: clothSize },
    { field: 'work_cloth_4_id', headerName: '(夏)インナー', width: 100, editable: true, type: 'singleSelect', valueOptions: clothSize },
    { field: 'work_cloth_5_id', headerName: '(冬)ブルゾン', width: 100, editable: true, type: 'singleSelect', valueOptions: clothSize },
    { field: 'work_cloth_6_id', headerName: '(冬)パンツ', width: 100, editable: true, type: 'singleSelect', valueOptions: pantshSize },
    { field: 'work_cloth_7_id', headerName: '(冬)防寒着', width: 100, editable: true, type: 'singleSelect', valueOptions: clothSize },
    {
      field: 'actions', headerName: '編集', type: 'actions', width: 100, cellClassName: 'actions',
      getActions: ({ id }) => {
        const isInEditMode = rowModesModel[id]?.mode === GridRowModes.Edit;

        if (isInEditMode) {
          return [
            <GridActionsCellItem
              key="save"
              icon={<SaveIcon />}
              label="Save"
              sx={{ color: 'primary.main' }}
              onClick={handleSaveClick(id)}
            />,
            <GridActionsCellItem
              key="cancel"
              icon={<CancelIcon />}
              label="Cancel"
              className="textPrimary"
              onClick={handleCancelClick(id)}
              color="inherit"
            />,
            <GridActionsCellItem
              key="reset"
              icon={<RestartAltIcon />}
              label="ResetPassword"
              onClick={handlePasswordResetClick(id)}
              color="inherit"
            />,
          ];
        }

        return [
          <GridActionsCellItem
            key="edit"
            icon={<EditIcon />}
            label="Edit"
            className="textPrimary"
            onClick={handleEditClick(id)}
            color="inherit"
          />,
          <GridActionsCellItem
            key="delete"
            icon={<DeleteIcon />}
            label="Delete"
            onClick={handleDeleteClick(id)}
            color="inherit"
          />,
        ];
      },
    },
  ];

  return (
    <Paper id="master-paper">
      <DataGrid
        rows={rows}
        columns={columns}
        initialState={{ pagination: { paginationModel } }}
        pageSizeOptions={[10, 15, 20]}
        editMode="row"
        rowModesModel={rowModesModel}
        onRowModesModelChange={handleRowModesModelChange}
        onRowEditStop={handleRowEditStop}
        processRowUpdate={processRowUpdate}
        slots={{ toolbar: EditToolbar }}
        slotProps={{ toolbar: { setRows, setRowModesModel, baseAddr: BASE_ADDR } }}
      />
    </Paper>
  );
};

export default UserPage;
