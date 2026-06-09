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
import axios from 'axios';                                      // HTTPリクエスト

const BASE_ADDR = "http://localhost:8080/App/sugie/Report/backend/";
//const BASE_ADDR = "http://www.sugie-k.com/report/backend/";

// 初期表示項目(ページ/項目数)
const paginationModel = { page: 1, pageSize: 10 };

// TypeScript用設定
declare module '@mui/x-data-grid' {
  interface ToolbarPropsOverrides {
    setRows: (newRows: (oldRows: GridRowsProp) => GridRowsProp) => void;
    setRowModesModel: (
      newModel: (oldModel: GridRowModesModel) => GridRowModesModel,
    ) => void;
  }
}

// ツールバー
const EditToolbar = (props: GridSlotProps['toolbar']) => {
  const { setRows, setRowModesModel } = props;

  const handleClick = () => {
    const id = randomId();
    setRows((oldRows) => [
      ...oldRows,
      { id, name: '',
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
        isNew: true },
    ]);
    setRowModesModel((oldModel) => ({
      ...oldModel,
      [id]: { mode: GridRowModes.Edit, fieldToFocus: 'name' },
    }));
  };

  return (
    // 追加ボタン
    <GridToolbarContainer>
      <Button color="primary" startIcon={<AddIcon />} onClick={handleClick}>
        追加
      </Button>
    </GridToolbarContainer>
  );
}

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
  {label: '未選択', value: 0},
  {label: 'S', value: 1},
  {label: 'M', value: 2},
  {label: 'L', value: 3},
  {label: 'XL', value: 4},
  {label: '2L', value: 5},
  {label: '3L', value: 6},
  {label: '4L', value: 7},
  {label: '5L', value: 8},
  {label: '特注', value: 9}
];

// 国籍
const nationality = [
  {label: '未選択', value: 0},
  {label: '日本人', value: 1},
  {label: '外国人', value: 2}
];

// シフト
const shift = [
  {label: '未選択', value: 0},
  //{label: '09:00 ～ 18:00　残業:18:00～　深夜残業:22:00～', value: 1}
];

// 血液型
const bloodType = [
  {label: '未選択', value: 0},
  {label: 'A型', value: 1},
  {label: 'B型', value: 2},
  {label: 'AB型', value: 3},
  {label: 'O型', value: 4}
];

// 社員マスタページ
const UserPage = () => {
  const [rows, setRows] = React.useState<GridRowsProp>(initialRows);
  //const [loading, setLoading] = useState(true);
  //const [error, setError] = useState(undefined);
  const [rowModesModel, setRowModesModel] = React.useState<GridRowModesModel>({});

  // DBデータの取得
  useEffect(() => {
    const fetchData = async() => {
      try
      {
        // APIエンドポイントからusersデータを取得
        const user_response = await axios.get<GridRowsProp>(BASE_ADDR + "/m_users/select.php");

        // データを設定
        setRows(user_response.data);

        // APIエンドポイントからshiftsデータを取得
        const shift_response = await axios.get<GridRowsProp>(BASE_ADDR + "/m_shifts/select.php");

        //setLoading(false);
      }
      catch (err)
      {
        //setError(err.message);
        //setLoading(false);
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

    // IDが数値の場合は処理続行(新規追加項目は英数のIDとなるため除外できる)
    if (typeof id === "number")
    {
      //const result = axios.post(BASE_ADDR + '/m_users/delete.php', {
      axios.post(BASE_ADDR + '/m_users/delete.php', {
        id: id
      });
    }
  };

  // 編集キャンセルボタンクリックイベント
  const handleCancelClick = (id: GridRowId) => () => {
    setRowModesModel({
      ...rowModesModel,
      [id]: { mode: GridRowModes.View, ignoreModifications: true },
    });

    const editedRow = rows.find((row) => row.id === id);
    if (editedRow!.isNew) {
      setRows(rows.filter((row) => row.id !== id));
    }
  };

  // 編集完了イベント
  const processRowUpdate = (newRow: GridRowModel) => {
    const updatedRow = { ...newRow, isNew: false };
    setRows(rows.map((row) => (row.id === newRow.id ? updatedRow : row)));
    console.log('一覧：' + JSON.stringify(newRow));

    if (newRow.name === "")
    {
      window.alert("氏名が未入力のため登録できません。");
    }
    else
    {
      // 新規登録
      if (newRow.isNew)
      {
        //const result = axios.post(BASE_ADDR + '/m_users/insert.php', {
        axios.post(BASE_ADDR + '/m_users/insert.php', {
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
      // 編集
      else
      {
        //const result = axios.post(BASE_ADDR + '/m_users/update.php', {
        axios.post(BASE_ADDR + '/m_users/update.php', {
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
    }

    return updatedRow;
  };

  const handleRowModesModelChange = (newRowModesModel: GridRowModesModel) => {
    setRowModesModel(newRowModesModel);
  };

  // DataGridの列項目
  const columns: GridColDef[] = [
    { field: 'name', headerName: '氏名', width: 150, editable: true },
    { field: 'is_authorized', headerName: '管理者', width: 90, editable: true, type: 'boolean' },
    { field: 'nationality_id', headerName: '区分', width: 100, editable: true, type: 'singleSelect', valueOptions: nationality },
    { field: 'shift_id', headerName: 'シフト設定', width: 420, editable: true, type: 'singleSelect', valueOptions: shift },
    { field: 'blood_type_id', headerName: '血液型', width: 100, editable: true, type: 'singleSelect', valueOptions: bloodType },
    { field: 'health_checked_on', headerName: '健康診断日', width: 130, editable: true, renderEditCell: (params) => <DatePickerCell {...params} /> },
    { field: 'paid_holidays_num', headerName: '有休日数', width: 80, editable: true },
    { field: 'retiremented_on', headerName: '退職日', width: 130, editable: true, renderEditCell: (params) => <DatePickerCell {...params} /> },
    { field: 'work_cloth_1_id', headerName: '(夏)ブルゾン', width: 100, editable: true, type: 'singleSelect', valueOptions: clothSize },
    { field: 'work_cloth_2_id', headerName: '(夏)パンツ', width: 100, editable: true, type: 'singleSelect', valueOptions: clothSize },
    { field: 'work_cloth_3_id', headerName: '(夏)空調服', width: 100, editable: true, type: 'singleSelect', valueOptions: clothSize },
    { field: 'work_cloth_4_id', headerName: '(夏)インナー', width: 100, editable: true, type: 'singleSelect', valueOptions: clothSize },
    { field: 'work_cloth_5_id', headerName: '(冬)ブルゾン', width: 100, editable: true, type: 'singleSelect', valueOptions: clothSize },
    { field: 'work_cloth_6_id', headerName: '(冬)パンツ', width: 100, editable: true, type: 'singleSelect', valueOptions: clothSize },
    { field: 'work_cloth_7_id', headerName: '(冬)防寒着', width: 100, editable: true, type: 'singleSelect', valueOptions: clothSize },
    { field: 'actions', headerName: '編集', type: 'actions', width: 100, cellClassName: 'actions',
      getActions: ({ id }) => {
        const isInEditMode = rowModesModel[id]?.mode === GridRowModes.Edit;

        if (isInEditMode) {
          return [
            // 保存ボタン
            <GridActionsCellItem
              icon={<SaveIcon />}
              label="Save"
              sx={{
                color: 'primary.main',
              }}
              onClick={handleSaveClick(id)}
            />,
            // キャンセルボタン
            <GridActionsCellItem
              icon={<CancelIcon />}
              label="Cancel"
              className="textPrimary"
              onClick={handleCancelClick(id)}
              color="inherit"
            />,
          ];
        }

        return [
          // 編集ボタン
          <GridActionsCellItem
            icon={<EditIcon />}
            label="Edit"
            className="textPrimary"
            onClick={handleEditClick(id)}
            color="inherit"
          />,
          // 削除ボタン
          <GridActionsCellItem
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
        slotProps={{ toolbar: { setRows, setRowModesModel } }}
      />
    </Paper>
  );
}

export default UserPage;