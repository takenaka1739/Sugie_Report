import { useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  FormControl,
  FormControlLabel,
  InputLabel,
  MenuItem,
  Paper,
  Select,
  Stack,
  Switch,
  TextField,
  Typography,
} from '@mui/material';
import axios from 'axios';

const BASE_ADDR = (
  process.env.REACT_APP_API_BASE ||
  (typeof window !== 'undefined' && (
    window.location.hostname === 'localhost' ||
    window.location.hostname === '127.0.0.1'
  )
    ? 'http://localhost/Report/backend'
    : `${window.location.origin}/report/backend`)
).replace(/\/+$/, '');

type EncryptionType = 'none' | 'ssl' | 'tls' | 'starttls';

type MailSettingsResponse = {
  id?: number | null;
  is_enabled?: number | string | boolean | null;
  recipient_mail?: string | null;
  cc_mail?: string | null;
  sender_name?: string | null;
  sender_mail?: string | null;
  smtp_host?: string | null;
  smtp_port?: number | string | null;
  smtp_user?: string | null;
  smtp_password?: string | null;
  encryption_type?: string | null;
  subject?: string | null;
  body_header?: string | null;
} | null;

type MailSettingsForm = {
  id: number | null;
  is_enabled: boolean;
  recipient_mail: string;
  cc_mail: string;
  sender_name: string;
  sender_mail: string;
  smtp_host: string;
  smtp_port: string;
  smtp_user: string;
  smtp_password: string;
  encryption_type: EncryptionType;
  subject: string;
  body_header: string;
};

const defaultForm: MailSettingsForm = {
  id: null,
  is_enabled: true,
  recipient_mail: '',
  cc_mail: '',
  sender_name: '',
  sender_mail: '',
  smtp_host: '',
  smtp_port: '587',
  smtp_user: '',
  smtp_password: '',
  encryption_type: 'tls',
  subject: '',
  body_header: '',
};

const labels = {
  title: '\u30e1\u30fc\u30eb\u8a2d\u5b9a\u30de\u30b9\u30bf',
  description: '\u7acb\u66ff\u91d1\u767b\u9332\u6642\u306e\u901a\u77e5\u30e1\u30fc\u30eb\u8a2d\u5b9a\u3092\u884c\u3044\u307e\u3059\u3002',
  enabled: '\u30e1\u30fc\u30eb\u901a\u77e5\u3092\u6709\u52b9\u306b\u3059\u308b',
  recipient: '\u901a\u77e5\u5148\u30e1\u30fc\u30eb\u30a2\u30c9\u30ec\u30b9',
  cc: 'CC\u30e1\u30fc\u30eb\u30a2\u30c9\u30ec\u30b9',
  senderName: '\u9001\u4fe1\u8005\u540d',
  senderMail: '\u9001\u4fe1\u5143\u30e1\u30fc\u30eb\u30a2\u30c9\u30ec\u30b9',
  smtpHost: 'SMTP\u30db\u30b9\u30c8',
  smtpPort: 'SMTP\u30dd\u30fc\u30c8',
  smtpUser: 'SMTP\u30e6\u30fc\u30b6\u30fc\u540d',
  smtpPassword: 'SMTP\u30d1\u30b9\u30ef\u30fc\u30c9',
  encryption: '\u6697\u53f7\u5316\u65b9\u5f0f',
  subject: '\u30e1\u30fc\u30eb\u4ef6\u540d',
  bodyHeader: '\u30e1\u30fc\u30eb\u672c\u6587\u5148\u982d\u30e1\u30c3\u30bb\u30fc\u30b8',
  save: '\u4fdd\u5b58',
  saving: '\u4fdd\u5b58\u4e2d...',
  loadError: '\u30e1\u30fc\u30eb\u8a2d\u5b9a\u306e\u8aad\u8fbc\u306b\u5931\u6557\u3057\u307e\u3057\u305f\u3002',
  saveSuccess: '\u30e1\u30fc\u30eb\u8a2d\u5b9a\u3092\u4fdd\u5b58\u3057\u307e\u3057\u305f\u3002',
  saveError: '\u30e1\u30fc\u30eb\u8a2d\u5b9a\u306e\u4fdd\u5b58\u306b\u5931\u6557\u3057\u307e\u3057\u305f\u3002',
  requiredRecipient: '\u901a\u77e5\u5148\u30e1\u30fc\u30eb\u30a2\u30c9\u30ec\u30b9\u3092\u5165\u529b\u3057\u3066\u304f\u3060\u3055\u3044\u3002',
  requiredSenderName: '\u9001\u4fe1\u8005\u540d\u3092\u5165\u529b\u3057\u3066\u304f\u3060\u3055\u3044\u3002',
  requiredSenderMail: '\u9001\u4fe1\u5143\u30e1\u30fc\u30eb\u30a2\u30c9\u30ec\u30b9\u3092\u5165\u529b\u3057\u3066\u304f\u3060\u3055\u3044\u3002',
  requiredHost: 'SMTP\u30db\u30b9\u30c8\u3092\u5165\u529b\u3057\u3066\u304f\u3060\u3055\u3044\u3002',
  requiredPort: 'SMTP\u30dd\u30fc\u30c8\u3092\u5165\u529b\u3057\u3066\u304f\u3060\u3055\u3044\u3002',
  requiredUser: 'SMTP\u30e6\u30fc\u30b6\u30fc\u540d\u3092\u5165\u529b\u3057\u3066\u304f\u3060\u3055\u3044\u3002',
  requiredPassword: 'SMTP\u30d1\u30b9\u30ef\u30fc\u30c9\u3092\u5165\u529b\u3057\u3066\u304f\u3060\u3055\u3044\u3002',
  requiredSubject: '\u30e1\u30fc\u30eb\u4ef6\u540d\u3092\u5165\u529b\u3057\u3066\u304f\u3060\u3055\u3044\u3002',
  noEncryption: '\u306a\u3057',
};

const fieldGridSx = {
  display: 'grid',
  gap: 2,
  gridTemplateColumns: {
    xs: '1fr',
    md: 'repeat(2, minmax(0, 1fr))',
  },
};

const MailSettingPage = () => {
  const [form, setForm] = useState<MailSettingsForm>(defaultForm);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  useEffect(() => {
    const fetchSettings = async () => {
      try {
        const response = await axios.get<MailSettingsResponse>(`${BASE_ADDR}/m_mail_settings/select.php`, {
          headers: { Accept: 'application/json' },
        });
        if (response.data) {
          setForm({
            id: response.data.id ?? null,
            is_enabled: Boolean(Number(response.data.is_enabled ?? 1)),
            recipient_mail: response.data.recipient_mail ?? '',
            cc_mail: response.data.cc_mail ?? '',
            sender_name: response.data.sender_name ?? '',
            sender_mail: response.data.sender_mail ?? '',
            smtp_host: response.data.smtp_host ?? '',
            smtp_port: String(response.data.smtp_port ?? 587),
            smtp_user: response.data.smtp_user ?? '',
            smtp_password: response.data.smtp_password ?? '',
            encryption_type: (response.data.encryption_type ?? 'tls') as EncryptionType,
            subject: response.data.subject ?? '',
            body_header: response.data.body_header ?? '',
          });
        }
      } catch (err: any) {
        const status = err?.response?.status;
        setMessage({
          type: 'error',
          text: status ? `${labels.loadError} (HTTP ${status})` : labels.loadError,
        });
      } finally {
        setLoading(false);
      }
    };

    fetchSettings();
  }, []);

  const handleChange = <K extends keyof MailSettingsForm>(key: K, value: MailSettingsForm[K]) => {
    setForm((prev) => ({ ...prev, [key]: value }));
  };

  const requiredError = useMemo(() => {
    if (!form.recipient_mail.trim()) return labels.requiredRecipient;
    if (!form.sender_name.trim()) return labels.requiredSenderName;
    if (!form.sender_mail.trim()) return labels.requiredSenderMail;
    if (!form.smtp_host.trim()) return labels.requiredHost;
    if (!form.smtp_port.trim()) return labels.requiredPort;
    if (!form.smtp_user.trim()) return labels.requiredUser;
    if (!form.smtp_password.trim()) return labels.requiredPassword;
    if (!form.subject.trim()) return labels.requiredSubject;
    return '';
  }, [form]);

  const handleSubmit = async () => {
    if (requiredError) {
      setMessage({ type: 'error', text: requiredError });
      return;
    }

    setSaving(true);
    setMessage(null);

    try {
      const payload = {
        id: form.id,
        is_enabled: form.is_enabled ? 1 : 0,
        recipient_mail: form.recipient_mail.trim(),
        cc_mail: form.cc_mail.trim(),
        sender_name: form.sender_name.trim(),
        sender_mail: form.sender_mail.trim(),
        smtp_host: form.smtp_host.trim(),
        smtp_port: Number(form.smtp_port),
        smtp_user: form.smtp_user.trim(),
        smtp_password: form.smtp_password,
        encryption_type: form.encryption_type,
        subject: form.subject.trim(),
        body_header: form.body_header,
      };

      const response = await axios.post<{ id?: number }>(`${BASE_ADDR}/m_mail_settings/save.php`, payload, {
        headers: { 'Content-Type': 'application/json' },
      });

      if (response.data?.id) {
        setForm((prev) => ({ ...prev, id: Number(response.data.id) }));
      }

      setMessage({ type: 'success', text: labels.saveSuccess });
    } catch (err: any) {
      const serverMessage = err?.response?.data?.message;
      setMessage({
        type: 'error',
        text: serverMessage || labels.saveError,
      });
    } finally {
      setSaving(false);
    }
  };

  return (
    <Paper id="master-paper" sx={{ p: 3 }}>
      <Stack spacing={3}>
        <Box>
          <Typography variant="h5" gutterBottom>{labels.title}</Typography>
          <Typography variant="body2" color="text.secondary">
            {labels.description}
          </Typography>
        </Box>

        {message && <Alert severity={message.type}>{message.text}</Alert>}

        <FormControlLabel
          control={
            <Switch
              checked={form.is_enabled}
              onChange={(event) => handleChange('is_enabled', event.target.checked)}
            />
          }
          label={labels.enabled}
        />

        <Box sx={fieldGridSx}>
          <TextField
            label={labels.recipient}
            value={form.recipient_mail}
            onChange={(event) => handleChange('recipient_mail', event.target.value)}
            fullWidth
            required
            disabled={loading}
          />
          <TextField
            label={labels.cc}
            value={form.cc_mail}
            onChange={(event) => handleChange('cc_mail', event.target.value)}
            fullWidth
            disabled={loading}
          />
          <TextField
            label={labels.senderName}
            value={form.sender_name}
            onChange={(event) => handleChange('sender_name', event.target.value)}
            fullWidth
            required
            disabled={loading}
          />
          <TextField
            label={labels.senderMail}
            value={form.sender_mail}
            onChange={(event) => handleChange('sender_mail', event.target.value)}
            fullWidth
            required
            disabled={loading}
          />
          <TextField
            label={labels.smtpHost}
            value={form.smtp_host}
            onChange={(event) => handleChange('smtp_host', event.target.value)}
            fullWidth
            required
            disabled={loading}
          />
          <Box sx={{ ...fieldGridSx, gridTemplateColumns: { xs: '1fr', md: 'minmax(0, 1fr) minmax(0, 1fr)' } }}>
            <TextField
              label={labels.smtpPort}
              value={form.smtp_port}
              onChange={(event) => handleChange('smtp_port', event.target.value)}
              fullWidth
              required
              disabled={loading}
            />
            <FormControl fullWidth disabled={loading}>
              <InputLabel id="encryption-type-label">{labels.encryption}</InputLabel>
              <Select
                labelId="encryption-type-label"
                label={labels.encryption}
                value={form.encryption_type}
                onChange={(event) => handleChange('encryption_type', event.target.value as EncryptionType)}
              >
                <MenuItem value="tls">TLS</MenuItem>
                <MenuItem value="ssl">SSL</MenuItem>
                <MenuItem value="starttls">STARTTLS</MenuItem>
                <MenuItem value="none">{labels.noEncryption}</MenuItem>
              </Select>
            </FormControl>
          </Box>
          <TextField
            label={labels.smtpUser}
            value={form.smtp_user}
            onChange={(event) => handleChange('smtp_user', event.target.value)}
            fullWidth
            required
            disabled={loading}
          />
          <TextField
            label={labels.smtpPassword}
            type="password"
            value={form.smtp_password}
            onChange={(event) => handleChange('smtp_password', event.target.value)}
            fullWidth
            required
            disabled={loading}
          />
        </Box>

        <TextField
          label={labels.subject}
          value={form.subject}
          onChange={(event) => handleChange('subject', event.target.value)}
          fullWidth
          required
          disabled={loading}
        />
        <TextField
          label={labels.bodyHeader}
          value={form.body_header}
          onChange={(event) => handleChange('body_header', event.target.value)}
          fullWidth
          multiline
          minRows={6}
          disabled={loading}
        />

        <Box>
          <Button variant="contained" onClick={handleSubmit} disabled={loading || saving}>
            {saving ? labels.saving : labels.save}
          </Button>
        </Box>
      </Stack>
    </Paper>
  );
};

export default MailSettingPage;
