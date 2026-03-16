const express = require('express');
const axios = require('axios');
const fs = require('fs');
const app = express();
const port = process.env.PORT || 3000;

app.use(express.json());

// Pastebin raw 링크 (키 목록 갱신용)
const PASTEBIN_RAW = "https://pastebin.com/raw/Qja144cQ";  // ← 바꾸기

// 사용 기록 저장 파일 (Render 무료는 휘발성 → 재시작 시 초기화됨)
const USED_FILE = './used.json';
let usedKeys = {};

if (fs.existsSync(USED_FILE)) {
  usedKeys = JSON.parse(fs.readFileSync(USED_FILE, 'utf8'));
}

// 키 목록 캐시 (주기적으로 Pastebin에서 갱신)
let validKeys = [];

async function loadValidKeys() {
  try {
    const res = await axios.get(PASTEBIN_RAW);
    validKeys = res.data.trim().split('\n').map(k => k.trim()).filter(k => k);
    console.log(`Loaded ${validKeys.length} valid keys from Pastebin`);
  } catch (e) {
    console.error("Pastebin load failed", e);
  }
}

loadValidKeys(); // 시작 시 로드
setInterval(loadValidKeys, 600000); // 10분마다 갱신

app.post('/validate', (req, res) => {
  const { key, hwid } = req.body;

  if (!key || !hwid) {
    return res.status(400).json({ success: false, message: "키 또는 HWID 누락" });
  }

  const normalizedKey = key.trim().toUpperCase();

  if (!validKeys.includes(normalizedKey)) {
    return res.json({ success: false, message: "유효하지 않은 키" });
  }

  const keyUsed = usedKeys[normalizedKey] || { uses: 0, hwids: [] };

  if (keyUsed.uses >= 1) {  // 1회 사용 제한 (원하는 숫자로 변경)
    return res.json({ success: false, message: "이미 사용된 키입니다" });
  }

  // HWID 중복 체크 (같은 HWID 재사용 가능하게 하려면 이 부분 주석)
  if (keyUsed.hwids.includes(hwid)) {
    return res.json({ success: false, message: "이미 이 기기에서 사용됨" });
  }

  // 사용 기록 저장
  usedKeys[normalizedKey] = {
    uses: keyUsed.uses + 1,
    hwids: [...keyUsed.hwids, hwid],
    lastUsed: new Date().toISOString()
  };

  fs.writeFileSync(USED_FILE, JSON.stringify(usedKeys, null, 2));

  res.json({ success: true, message: "인증 성공" });
});

app.listen(port, () => {
  console.log(`Key server running on port ${port}`);
});
