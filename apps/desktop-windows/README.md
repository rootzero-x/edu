# TISU Edu — Windows ilovasi

TISU Edu platformasining Windows 10/11 uchun native ilovasi: **C# / .NET 8 + WinUI 3 (Windows App SDK 1.6)**, MVVM
(CommunityToolkit.Mvvm), Fluent Design (NavigationView, Mica, o'z sarlavha paneli, Segoe Fluent Icons,
yorug'/qorong'i/tizim mavzusi, yuqori DPI, klaviatura bilan boshqarish).

Ilova `https://api.tisu.uzstudents.uz/v1` API'si bilan ishlaydi; menyu nomlari, kirish sahifasi matnlari, davomat
holatlari, kursga kirish rad etilgandagi xabar va boshqa biznes matnlar `GET /v1/public/settings` dan olinadi
(diskda keshlanadi — oflayn ishga tushganda ham ko'rinadi).

## Tuzilma

```
apps/desktop-windows/
├── TisuEdu.Desktop.sln
├── src/
│   ├── TisuEdu.Core/          # platformadan mustaqil (net8.0): API mijozi, modellar, ViewModel'lar
│   │   ├── Api/               # ApiClient (bearer, 401 → chiqish, oflayn kesh), TisuApi (endpointlar), JSON
│   │   ├── Services/          # Session, SettingsService/SiteSettings, AnnouncementWatcher, Format, Csv, Preferences
│   │   └── ViewModels/        # har bir sahifa uchun ViewModel
│   └── TisuEdu.App/           # WinUI 3 ilova (net8.0-windows10.0.19041.0)
│       ├── Views/             # sahifalar (XAML)
│       ├── Services/          # Credential Locker, toast, fayl saqlash, navigatsiya, mavzu
│       ├── Assets/            # ikonka va MSIX logolari (sayt nomi asosida)
│       └── Package.appxmanifest
└── tests/TisuEdu.Core.Tests/  # xUnit (Linux'da ham ishlaydi)
```

Biznes mantiq `TisuEdu.Core` da — shuning uchun u har qanday OS'da quriladi va testlanadi; WinUI qatlami yupqa.

## Imkoniyatlar

| Bo'lim | Nima qiladi | Ruxsat |
|---|---|---|
| Kirish | Telefon + parol; Telegram deep-link (`auth/telegram/start` → t.me havolasi → har 2 s `poll`) | — |
| Bosh sahifa | `dashboard/summary`: bugungi darslar, statistikalar, belgilanmagan darslar, so'nggi yangiliklar | hamma |
| Kurslar | kurslar → modullar → darslar → materiallar; testlar ro'yxati. Kirish yo'q bo'lsa — API qaytargan `COURSE_ACCESS_DENIED` matni va "yozish" tugmasi | `course.view` |
| Testlar | o'quvchi: boshlash/davom ettirish, vaqt hisoblagichi (tugasa avtomatik topshiradi), natija va tekshiruv; o'qituvchi: natijalar jadvali | `quiz.take` / `quiz.manage` |
| Guruhlar | ro'yxat (qidiruv, faol/barchasi), guruh: jadval, o'quvchilar va davomat foizi, darslar | hamma (ko'rinish doirasi serverda) |
| Davomat | belgilanmagan darslar; davomat varaqasi — holat tugmalari yoki kelgan vaqt (holatni server aniqlaydi), izoh, mavzu, "qolganlar keldi", o'zgarishlar tarixi. **Tahrir muddati, sabab majburiyligi va xabarlar serverning `edit` oynasidan** olinadi | `attendance.mark` |
| Yangiliklar | sahifalab yuklash, ochilganda o'qilgan, menyuda o'qilmaganlar belgisi; yangi e'lonlarga Windows bildirishnomasi (`announcements/unread` davriy so'rovi) | hamma |
| Sertifikatlar | ro'yxat, ochish (`certificate.public_base_url`), havolani nusxalash | hamma |
| Hisobotlar | umumiy ko'rsatkichlar, davomat (kunlar diagrammasi, guruhlar, xavf ostidagilar), testlar; CSV: server `attendance.csv` + guruhlar/testlar (Excel uchun BOM, `;`, formula-injection himoyasi) | `report.view` |
| Profil | ism/familiya, parol (Telegram orqali kelganlarga birinchi parol), chiqish | hamma |
| Ilova sozlamalari | mavzu, bildirishnomalar, versiya | — |
| To'lovlar | balanslar/qarzdorlar (qidiruv, filtr, tartib), davr hisoboti, o'quvchi jurnali; to'lov (takroriy bosishdan himoyalangan `request_id`), tuzatish, bekor qilish (sabab bilan); CSV (`balances.csv`, `entries.csv`). O'quvchi — o'z balansi (`billing/me`) | `billing.manage` / o'quvchi |
| Foydalanuvchilar | qidiruv, rol va "tasdiq kutmoqda" filtrlari, yaratish, tahrirlash, parol o'rnatish, bloklash, botdan kelganlarni tasdiqlash | `user.manage` |
| Tizim sozlamalari | barcha sozlamalar bo'lim va qidiruv bo'yicha, turiga mos tahrir (son, ha/yo'q, matn, ro'yxat/obyekt — JSON), standartga qaytarish | `settings.manage` |
| Kontentni tahrirlash | kurs (yaratish, tahrirlash, chop etish, o'chirish), modullar, darslar, materiallar (video/fayl/havola/matn), testlar va savollar (`*` — to'g'ri variant), e'lonlar (qoralama, auditoriya, chop etish/yashirish), guruhlar (haftalik jadval, darslarni yaratish, a'zolar), sertifikat berish/bekor qilish, darsni bekor qilish | kurs muallifi (`can_edit`), `quiz.manage`, `announcement.manage`, `group.manage` |

Tahrirlash formalari bitta umumiy dialogda (`IForms` → ContentDialog): server validatsiya xatosi (422) qaytarsa dialog
yopilmaydi va xato matni ko'rsatiladi. Telegram orqali kirishda **QR-kod** ko'rsatiladi (telefon kamerasi bilan
skanerlash). YouTube/Vimeo videolari (`course.embed_hosts`) ilova ichida **WebView2** da ochiladi.

### Xavfsizlik va ishonchlilik

- Token **Windows Credential Locker** (`PasswordVault`) da; u ishlamasa — DPAPI (joriy foydalanuvchi) bilan
  shifrlangan fayl. Ochiq matnda saqlanmaydi.
- Har qanday `401` → token o'chiriladi, kesh tozalanadi, kirish oynasi "Sessiya tugadi" xabari bilan ochiladi.
- Oflayn: GET javoblari foydalanuvchi bo'yicha diskda keshlanadi (`%LOCALAPPDATA%\TisuEdu\cache\u{id}`); aloqa
  bo'lmasa oxirgi ma'lumot "Oflayn rejim" belgisi bilan ko'rsatiladi; sarlavhada "Oflayn" belgisi.
- Barcha so'rovlar async, sahifa ma'lumotlari parallel so'raladi (`Task.WhenAll`), sahifadan chiqilganda bekor
  qilinadi. Ochiq sozlamalar 60 s keshlanadi (server `Cache-Control: max-age=60`).
- Ilova bitta nusxada ishlaydi; bildirishnoma bosilsa ochiq oyna oldinga chiqib, kerakli e'lonni ochadi.

## Talablar

- Windows 10 1809 (17763) yoki yangi; Windows 11'da Mica fon (Windows 10'da akril).
- Ishlab chiqish: Visual Studio 2022 (17.8+) "Windows application development" (WinUI) workload'i bilan,
  yoki .NET 8 SDK + MSBuild (Build Tools).

## Ishga tushirish (ishlab chiqish)

```powershell
cd apps/desktop-windows
dotnet test tests/TisuEdu.Core.Tests          # Core testlari (Windows/Linux/macOS)
```

Visual Studio'da `TisuEdu.Desktop.sln` ni oching, `TisuEdu.App` ni start project qiling, platformani `x64`
tanlang va **F5**. Profil: "TisuEdu.App (Package)" — MSIX sifatida, yoki "Unpackaged".

Buyruq qatoridan (paketsiz):

```powershell
msbuild src/TisuEdu.App/TisuEdu.App.csproj /restore /p:Configuration=Debug /p:Platform=x64 /p:WindowsPackageType=None /p:WindowsAppSDKSelfContained=true /p:RuntimeIdentifier=win-x64
.\src\TisuEdu.App\bin\x64\Debug\net8.0-windows10.0.19041.0\win-x64\TisuEdu.exe
```

Boshqa serverga ulash (masalan, staging): `TISU_API_URL` muhit o'zgaruvchisi (`https://...` — `/v1` siz).

## Reliz (build va paketlash)

CI: `.github/workflows/desktop-windows.yml` (`windows-latest`), `apps/desktop-windows/**` o'zgarganda har
push/PR'da ishlaydi va x64 + ARM64 uchun artefakt yuklaydi:

| Fayl | Nima |
|---|---|
| `TisuEdu-<ver>-win-x64-portable.zip` | Paketsiz, self-contained (.NET + Windows App SDK ichida). Ochib, `TisuEdu.exe` ni ishga tushiring — o'rnatish shart emas |
| `TisuEdu-<ver>-win-x64.msix` | MSIX paket (Start menyu, toza o'chirish, bildirishnomalar) |
| `TisuEdu-CI-win-<arch>.cer` | CI'ning o'z-o'zidan imzolangan sertifikati (faqat sinov uchun) |

Versiya: oddiy push'da `1.0.<run_number>`.

### GitHub Release

`desktop-windows-vX.Y.Z` tag'i push qilinganda CI build qiladi va **Releases** sahifasida
"TISU Edu Windows desktop-windows-vX.Y.Z" relizini yaratib, zip/msix/cer fayllarni biriktiradi (versiya tag'dan olinadi):

```bash
git tag desktop-windows-v1.0.0 && git push origin desktop-windows-v1.0.0
```

### Paketsiz build (qo'lda)

```powershell
msbuild src/TisuEdu.App/TisuEdu.App.csproj /t:Publish /restore /p:Configuration=Release /p:Platform=x64 `
  /p:RuntimeIdentifier=win-x64 /p:SelfContained=true /p:WindowsPackageType=None /p:WindowsAppSDKSelfContained=true `
  /p:PublishReadyToRun=true /p:PublishDir=artifacts\unpackaged\
```

### MSIX (qo'lda)

```powershell
msbuild src/TisuEdu.App/TisuEdu.App.csproj /restore /p:Configuration=Release /p:Platform=x64 /p:RuntimeIdentifier=win-x64 `
  /p:GenerateAppxPackageOnBuild=true /p:UapAppxPackageBuildMode=SideloadOnly /p:AppxBundle=Never `
  /p:AppxPackageDir=artifacts\msix\ /p:AppxPackageSigningEnabled=true `
  /p:PackageCertificateKeyFile=C:\path\cert.pfx /p:PackageCertificatePassword=***
```

`Package.appxmanifest` dagi `Publisher="CN=TISU Edu"` sertifikat subject'iga teng bo'lishi shart.

**CI sinov paketini o'rnatish:** `TisuEdu-CI-win-x64.cer` ni administrator sifatida "Local Machine → Trusted People"
ga import qiling (`Import-Certificate -FilePath TisuEdu-CI-win-x64.cer -CertStoreLocation Cert:\LocalMachine\TrustedPeople`),
so'ng `.msix` ni ikki marta bosing. Har CI ishga tushishida yangi sertifikat yaratiladi.

**Haqiqiy reliz:** kod imzolash sertifikati (subject `CN=TISU Edu`, yoki manifestdagi Publisher'ni o'zgartiring)
ni repo secrets'ga qo'shing — CI avtomatik uni ishlatadi:

- `MSIX_CERT_BASE64` — `.pfx` faylning base64 ko'rinishi (`[Convert]::ToBase64String([IO.File]::ReadAllBytes("cert.pfx"))`)
- `MSIX_CERT_PASSWORD` — parol

Microsoft Store uchun: Partner Center'dagi Identity (Name/Publisher) ni manifestga yozing va
`UapAppxPackageBuildMode=StoreUpload` bilan build qiling (Store o'zi imzolaydi).

## Brending

Ikonka va logolar (`src/TisuEdu.App/Assets`) sayt nomi ("TISU Edu" → "T") va `site.primary_color` (#2563eb)
asosida yaratilgan. Ishga tushganda ilovaning urg'u rangi (tugmalar, tanlov) `site.primary_color` dan olinadi,
oyna sarlavhasi — `site.name` dan.

## Ma'lum cheklovlar

- Fayl va havola materiallari brauzerda ochiladi; video WebView2 runtime bo'lmasa ham brauzerga o'tadi.
- Sprint 3 bo'limlari (uyga vazifa, taqvim, arizalar/CRM, audit jurnali, fayllar) hozircha faqat saytda.
- Bildirishnomalar ilova ochiq (yoki vazifalar panelida yig'ilgan) paytda so'rov orqali keladi; ilova
  yopilganda push yo'q (server Windows push xizmatiga ulanmagan).
