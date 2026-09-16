import React, {useState} from 'react';
import {Alert, Text} from 'react-native';
import {File, Paths} from 'expo-file-system';
import * as Sharing from 'expo-sharing';
import {api, apiOrigin} from '../../shared/api';
import {getSession, saveSession} from '../../shared/session';
import {pauseTracking} from '../location/engine';
import {Button, Card, Check, Notice, Title, ui} from '../../ui/components';

export function PrivacyPanel({onDeleted}: {onDeleted: () => void}) {
  const [exportId, setExportId] = useState('');
  const [status, setStatus] = useState('');
  const [busy, setBusy] = useState(false);
  const [confirmed, setConfirmed] = useState(false);
  const [error, setError] = useState('');
  async function run(action: () => Promise<void>) {
    if (busy) return;
    setBusy(true); setError('');
    try {await action();} catch (e) {setError(e instanceof Error ? e.message : 'Не удалось выполнить запрос.');} finally {setBusy(false);}
  }
  async function exportData() {
    if (!exportId) {
      const result = await api<{id: string; status: string}>('privacy/exports', 'POST');
      setExportId(result.id); setStatus(result.status); return;
    }
    const result = await api<{status: string}>(`privacy/exports/${exportId}`);
    setStatus(result.status);
    if (result.status !== 'completed') return;
    if (!await Sharing.isAvailableAsync()) throw new Error('Сохранение файла недоступно на этом устройстве.');
    const session = await getSession();
    if (!session) throw new Error('Войдите в аккаунт.');
    if (!__DEV__ && !apiOrigin.startsWith('https://')) throw new Error('Нужен HTTPS.');
    const file = new File(Paths.cache, `kirh-export-${exportId}.ndjson`);
    try {
      await File.downloadFileAsync(`${apiOrigin.replace(/\/$/, '')}/privacy/exports/${exportId}/download`, file, {headers: {Authorization: `Bearer ${session.access_token}`}, idempotent: true});
      await Sharing.shareAsync(file.uri, {mimeType: 'application/x-ndjson', dialogTitle: 'Сохранить мои данные'});
    } finally {if (file.exists) file.delete();}
  }
  function removeAccount() {
    Alert.alert('Удалить аккаунт?', 'Передача и доступ остановятся сразу. Очистка выполняется в фоне. Финансовый учёт и журнал согласий сохраняются в предусмотренных случаях. Передайте владение общей группой и отмените автопродление заранее.', [
      {text: 'Отмена', style: 'cancel'},
      {text: 'Удалить', style: 'destructive', onPress: () => void run(async () => {
        const paused = await pauseTracking();
        if (paused.pendingRevocation) throw new Error('Дождитесь сети и подтверждения остановки передачи.');
        await api('privacy/deletion', 'POST', {confirmed: true});
        await saveSession(null); onDeleted();
      })},
    ]);
  }
  return <Card><Title>Мои данные</Title>
    <Text style={ui.text}>Экспорт содержит ваши координаты. После сохранения защитите файл: передача в выбранное приложение выходит за пределы KIRH GEO.</Text>
    <Button disabled={busy} onPress={() => void run(exportData)}>{exportId ? 'Проверить и сохранить экспорт' : 'Запросить экспорт'}</Button>
    {!!status && <Text style={ui.text}>Статус экспорта: {status}</Text>}
    <Check value={confirmed} onChange={setConfirmed}>Понимаю последствия удаления аккаунта</Check>
    <Button tone="danger" disabled={busy || !confirmed} onPress={removeAccount}>Удалить аккаунт</Button>
    {!!error && <Notice danger>{error}</Notice>}
  </Card>;
}
