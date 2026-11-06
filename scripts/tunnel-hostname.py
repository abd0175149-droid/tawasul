#!/usr/bin/env python3
"""
التواصل — تسجيل نطاق فرعي على نفق Cloudflare المُدار من اللوحة.

النفق مُدار عن بُعد: cloudflared يجلب إعداده من Cloudflare ويتجاهل ingress
المحلي في config.yml. فالتسجيل يتم عبر واجهة Cloudflare لا بتعديل الملف.

هذا النفق يخدم عشرات النطاقات لمشاريع أخرى، فالسكربت:
  1. يقرأ الإعداد الحالي ويحفظ نسخة احتياطية مؤرّخة على القرص
  2. يُدرج قاعدة واحدة قبل أي قاعدة عامة (wildcard) تلتقط النطاق
  3. لا يمس أي قاعدة قائمة ولا يعيد ترتيبها
  4. يقرأ الإعداد بعد الكتابة ويتحقق أن العدد زاد واحدًا فقط

    python3 scripts/tunnel-hostname.py <hostname> <port> [--apply]

بلا ‎--apply‎ يعرض ما سيفعله ولا يكتب شيئًا.
"""

import base64
import json
import os
import re
import sys
import urllib.request
from datetime import datetime, timezone

CERT = os.path.expanduser('~/.cloudflared/cert.pem')
TUNNEL_ID = os.environ.get('TUNNEL_ID', 'b8f315ec-a311-4b7a-8891-7b9e37a43f73')
BACKUP_DIR = os.path.expanduser('~/cloudflared-backups')
API = 'https://api.cloudflare.com/client/v4'


def credentials():
    raw = open(CERT, encoding='utf-8').read()
    match = re.search(r'ARGO TUNNEL TOKEN-----(.*?)-----END', raw, re.S)
    if not match:
        sys.exit('لا يوجد رمز في cert.pem')
    payload = json.loads(base64.b64decode(match.group(1).strip() + '=='))
    return payload['apiToken'], payload['accountID']


def call(method, url, token, body=None):
    data = json.dumps(body).encode() if body is not None else None
    request = urllib.request.Request(url, data=data, method=method)
    request.add_header('Authorization', 'Bearer ' + token)
    request.add_header('Content-Type', 'application/json')
    with urllib.request.urlopen(request, timeout=45) as response:
        return json.loads(response.read())


def main():
    if len(sys.argv) < 3:
        sys.exit(__doc__)

    hostname, port = sys.argv[1], sys.argv[2]
    apply_changes = '--apply' in sys.argv

    token, account = credentials()
    endpoint = f'{API}/accounts/{account}/cfd_tunnel/{TUNNEL_ID}/configurations'

    current = call('GET', endpoint, token)
    if not current.get('success'):
        sys.exit('تعذّرت قراءة الإعداد: ' + json.dumps(current.get('errors'), ensure_ascii=False))

    config = current['result']['config']
    ingress = config['ingress']

    os.makedirs(BACKUP_DIR, exist_ok=True)
    stamp = datetime.now(timezone.utc).strftime('%Y%m%d-%H%M%S')
    backup = os.path.join(BACKUP_DIR, f'tunnel-config-{stamp}.json')
    with open(backup, 'w', encoding='utf-8') as handle:
        json.dump(config, handle, ensure_ascii=False, indent=2)
    print(f'نسخة احتياطية: {backup}  ({len(ingress)} قاعدة)')

    if any(rule.get('hostname') == hostname for rule in ingress):
        print(f'القاعدة موجودة أصلًا: {hostname}')
        return

    # موضع الإدراج: قبل أول قاعدة عامة تلتقط هذا النطاق، وإلا قبل الأخيرة
    suffix = hostname.split('.', 1)[1] if '.' in hostname else hostname
    position = len(ingress) - 1

    for index, rule in enumerate(ingress):
        host = rule.get('hostname', '')
        if host.startswith('*.') and suffix.endswith(host[2:]):
            position = index
            print(f'توجد قاعدة عامة «{host}» في الموضع {index}، فالإدراج قبلها.')
            break

    new_rule = {
        'hostname': hostname,
        'service': f'http://127.0.0.1:{port}',
        'originRequest': {'httpHostHeader': hostname},
    }

    ingress.insert(position, new_rule)
    print(f'ستُدرج: {hostname} ← http://127.0.0.1:{port}  في الموضع {position}')

    if not apply_changes:
        print('\nعرض فقط. أضف ‎--apply‎ للكتابة.')
        return

    result = call('PUT', endpoint, token, {'config': config})
    if not result.get('success'):
        sys.exit('فشلت الكتابة: ' + json.dumps(result.get('errors'), ensure_ascii=False))

    verify = call('GET', endpoint, token)
    rules = verify['result']['config']['ingress']
    found = [i for i, r in enumerate(rules) if r.get('hostname') == hostname]

    print(f'بعد الكتابة: {len(rules)} قاعدة، والقاعدة الجديدة في الموضع {found}')

    if len(rules) != len(ingress):
        sys.exit('عدد القواعد غير متوقع. راجع النسخة الاحتياطية.')

    print('تم. النفق يلتقط الإعداد الجديد خلال ثوانٍ بلا إعادة تشغيل.')


if __name__ == '__main__':
    main()
