"""HTTP checks against a disposable Magento admin, never an external host."""
import http.cookiejar
import re
import sys
import urllib.parse
import urllib.request
import urllib.error
import html as html_util
import time
import os
import subprocess

base, admin_path, username, password, root, mode = sys.argv[1:]
if base != "http://127.0.0.1:34700":
    raise SystemExit("Refusing any non-disposable HTTP origin")
jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
config = base + "/" + admin_path + "/system_config/edit/section/sendrepute/"
for attempt in range(3):
    try:
        response = opener.open(config, timeout=30)
        break
    except (urllib.error.URLError, TimeoutError):
        if attempt == 2:
            raise
        time.sleep(2)
guest = response.read().decode("utf-8", "replace")
if response.status != 200 or "login[username]" not in guest or "sendrepute_mail_enabled" in guest:
    raise RuntimeError("Unauthenticated admin config was not redirected to login")
print("Native admin unauthenticated configuration access redirected to login.")
form = re.search(r'name="form_key"[^>]*value="([^"]+)"', guest)
if not form:
    raise RuntimeError("Native admin login form has no form key")
login = response.url
data = urllib.parse.urlencode({
    "form_key": form.group(1), "login[username]": username,
    "login[password]": password,
}).encode()
response = opener.open(urllib.request.Request(login, data=data), timeout=40)
html = response.read().decode("utf-8", "replace")
if "login[username]" in html or "Dashboard" not in html:
    raise RuntimeError("Disposable Magento admin login failed")
print("Authenticated Magento admin session established.")
if mode == "restricted":
    form_key = re.search(r"FORM_KEY\s*=\s*'([^']+)'", html)
    if not form_key:
        raise RuntimeError("Restricted admin dashboard missing native form key")
    php = os.environ.get("MAGENTO_PHP_BIN", "php")
    key = subprocess.check_output([
        php, os.path.join(os.path.dirname(__file__), "installed-sign-url.php"),
        root, form_key.group(1),
    ], timeout=20).decode().strip()
    signed = (base + "/index.php/" + admin_path
              + "/admin/system_config/edit/section/sendrepute/key/" + key + "/")
    try:
        denied_response = opener.open(signed, timeout=35)
        denial = denied_response.read().decode("utf-8", "replace")
        final_url = denied_response.url
    except urllib.error.HTTPError as error:
        denial = error.read().decode("utf-8", "replace")
        final_url = error.url
    if 'class="access-denied-page"' not in denial or "sendrepute_mail_enabled" in denial:
        raise RuntimeError("Restricted admin did not receive native ACL denial: " + final_url)
    print("Native restricted-role ACL denied SendRepute configuration.")
    sys.exit(0)
if mode != "full":
    raise RuntimeError("Unknown admin test mode")
links = re.findall(r'href="([^"]*system_config/index[^"]*)"', html)
if not links:
    raise RuntimeError("Admin sidebar did not contain native configuration link")
index = opener.open(links[0], timeout=35).read().decode("utf-8", "replace")
links = re.findall(r'href="([^"]*system_config/edit/section/sendrepute/[^"]*)"', index)
if not links:
    raise RuntimeError("SendRepute section missing from native admin configuration navigation")
response = opener.open(links[0], timeout=35)
page = response.read().decode("utf-8", "replace")
if response.status != 200 or "sendrepute_mail_enabled" not in page:
    raise RuntimeError("Authenticated native admin configuration failed: "
                       + response.url + " field=" + str("sendrepute" in page)
                       + " login=" + str("login[username]" in page))
print("Native admin SendRepute configuration rendered.")
section_url = response.url
save = re.search(r'<form[^>]+action="([^"]*system_config/save[^"]*)"', page)
form = re.search(r'name="form_key"[^>]*value="([^"]+)"', page)
if not save or not form:
    raise RuntimeError("Native configuration form/action or CSRF key missing")
save_url = html_util.unescape(save.group(1))
payload = "<script>alert('task600_xss')</script>"
field = "groups[mail][fields][opt_in_routes][value]"
def post(values):
    req = urllib.request.Request(save_url, data=urllib.parse.urlencode(values).encode())
    return opener.open(req, timeout=35).read().decode("utf-8", "replace")

# Neither an authenticated session nor the signed URL substitutes for the
# native form key. This deliberately malicious value must NOT be persisted.
post({field: payload})
page = opener.open(section_url, timeout=35).read().decode("utf-8", "replace")
if html_util.escape(payload, quote=False) in page or payload in page:
    raise RuntimeError("Admin configuration accepted a POST without form_key")
print("Native admin CSRF rejected missing form key.")
post({"form_key": form.group(1), field: payload})
page = opener.open(section_url, timeout=35).read().decode("utf-8", "replace")
if payload in page or "&lt;script&gt;alert(&#039;task600_xss&#039;)&lt;/script&gt;" not in page:
    raise RuntimeError("Admin textarea failed to escape stored malicious content: "
                       + str(re.findall(r'.{0,70}task600_xss.{0,70}', page)[:2]))
print("Native admin configuration escaped stored script markup.")
# Restore opt-out: the test must not leave a potentially billable route.
post({"form_key": form.group(1), field: ""})