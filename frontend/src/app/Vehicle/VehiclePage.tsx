//import React, { useState, useEffect } from 'react';             // React
import React, { useEffect } from 'react';                       // React
import { Paper, Button, TextField } from '@mui/material';       // Material UI
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

/**
 * === APIベースURLの決定ロジック ===
 * 1) .env を最優先（REACT_APP_API_BASE）
 * 2) .env が無い／未設定時のフォールバック
 *    - localhost系 → http://localhost/Report/backend
 *    - それ以外    → {現在のホスト}/report/backend
 * ※ 末尾スラッシュは除去（結合時の // 防止）
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

// TypeScript用：ツールバーに baseAddr を渡す
declare module '@mui/x-data-grid' {
  interface ToolbarPropsOverrides {
    setRows: (newRows: (oldRows: GridRowsProp) => GridRowsProp) => void;
    setRowModesModel: (newModel: (oldModel: GridRowModesModel) => GridRowModesModel) => void;
    baseAddr: string;
  }
}

// ツールバー
const EditToolbar = (props: GridSlotProps['toolbar']) => {
  const { setRows, setRowModesModel, baseAddr } = props;

  const handleClickAdd = () => {
    const id = randomId();
    setRows((oldRows) => [
      ...oldRows,
      {
        id,
        number: '',
        model: '',
        inspected_on: '',
        liability_insuranced_on: '',
        voluntary_insuranced_on: '',
        isNew: true
      },
    ]);
    setRowModesModel((oldModel) => ({
      ...oldModel,
      [id]: { mode: GridRowModes.Edit, fieldToFocus: 'number' },
    }));
  };

  //　エクセル出力
  const handleExportXls = () => {
    const url = `${baseAddr}/m_vehicles/export_xls.php`;
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

      {/* 追加 */}
      <Button color="primary" startIcon={<AddIcon />} onClick={handleClickAdd}>
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

// 車両マスタページ
const VehiclePage = () => {
  const [rows, setRows] = React.useState<GridRowsProp>(initialRows);
  const [rowModesModel, setRowModesModel] = React.useState<GridRowModesModel>({});

  // DBデータの取得
  useEffect(() => {
    const fetchData = async () => {
      try {
        const response = await axios.get<GridRowsProp>(`${BASE_ADDR}/m_vehicles/select.php`, {
          headers: { 'Accept': 'application/json' },
        });
        setRows(response.data);
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

  const handleEditClick = (id: GridRowId) => () => {
    setRowModesModel({ ...rowModesModel, [id]: { mode: GridRowModes.Edit } });
  };
  const handleSaveClick = (id: GridRowId) => () => {
    setRowModesModel({ ...rowModesModel, [id]: { mode: GridRowModes.View } });
  };
  const handleDeleteClick = (id: GridRowId) => () => {
    setRows(rows.filter((row) => row.id !== id));
    if (typeof id === "number") {
      axios.post(`${BASE_ADDR}/m_vehicles/delete.php`, { id }).catch(() => {
        alert('削除APIでエラーが発生しました。');
      });
    }
  };
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

    if (newRow.number === "") {
      window.alert("ナンバーが未入力のため登録できません。");
      return updatedRow;
    }

    try {
      if ((newRow as any).isNew || isNaN(newRow.id as any)) {
        await axios.post(`${BASE_ADDR}/m_vehicles/insert.php`, {
          number: newRow.number,
          model: newRow.model,
          inspected_on: (newRow.inspected_on === '') ? null : newRow.inspected_on,
          liability_insuranced_on: (newRow.liability_insuranced_on === '') ? null : newRow.liability_insuranced_on,
          voluntary_insuranced_on: (newRow.voluntary_insuranced_on === '') ? null : newRow.voluntary_insuranced_on
        });
      } else {
        await axios.post(`${BASE_ADDR}/m_vehicles/update.php`, {
          id: newRow.id,
          number: newRow.number,
          model: newRow.model,
          inspected_on: (newRow.inspected_on === '') ? null : newRow.inspected_on,
          liability_insuranced_on: (newRow.liability_insuranced_on === '') ? null : newRow.liability_insuranced_on,
          voluntary_insuranced_on: (newRow.voluntary_insuranced_on === '') ? null : newRow.voluntary_insuranced_on
        });
      }

      const response = await axios.get<GridRowsProp>(`${BASE_ADDR}/m_vehicles/select.php`, {
        headers: { 'Accept': 'application/json' },
      });
      setRows(response.data);
    } catch {
      alert('登録/更新APIでエラーが発生しました。バックエンドのログを確認してください。');
    }

    return updatedRow;
  };

  const handleRowModesModelChange = (newRowModesModel: GridRowModesModel) => {
    setRowModesModel(newRowModesModel);
  };

  // DataGridの列
  const columns: GridColDef[] = [
    { field: 'number', headerName: 'ナンバー', width: 200, editable: true },
    { field: 'model', headerName: '車種', width: 200, editable: true },
    { field: 'inspected_on', headerName: '車検日', width: 180, editable: true, renderEditCell: (p) => <DatePickerCell {...p} /> },
    { field: 'liability_insuranced_on', headerName: '自賠責保険', width: 180, editable: true, renderEditCell: (p) => <DatePickerCell {...p} /> },
    { field: 'voluntary_insuranced_on', headerName: '任意保険', width: 180, editable: true, renderEditCell: (p) => <DatePickerCell {...p} /> },
    {
      field: 'actions',
      headerName: '編集',
      type: 'actions',
      width: 100,
      cellClassName: 'actions',
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

export default VehiclePage;
