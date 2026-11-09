# Manifest paczki ActiveFit

**Wersja:** 2026-11-05  
**Cel:** wypakować zawartość katalogu głównego ZIP do katalogu głównego repozytorium GitHub.

**To jest paczka dokumentacji i widoków referencyjnych, a nie gotowa aplikacja Laravel.**

## Zawartość

- `README.md`, `AGENTS.md`
- `docs/product/requirements.md`
- `docs/architecture/` — 8 plików Markdown (w tym ADR i specyfikacje)
- `docs/development/` — strategia testów i plan implementacji
- `design/reference-html/` — 12 oryginalnych plików HTML

## Zweryfikowane korekty w tej paczce

- Opis PDF przy sprzedaży na recepcji: wysyłka po skutecznym potwierdzeniu płatności, a nie po rozpoczęciu ważności.
- Akceptacja wersji regulaminu w checkout online: `CONFIRMED`, a nie `OPEN`.

## Kontrola integralności (SHA-256)

```text
8870daecb20a50b7c797d7de65ed04b231ad94ddc09aaaae0ae816c96cabdbff  AGENTS.md
65915c63a55dd94def872e5427299a03972cb9b4b89feaddfa79f6c0c61e80cf  README.md
9f7a33c32a00eb7b0b3f415ef259dc3da04bc55784d9756f223023609cf09ae9  design/reference-html/activefit-admin-aktualnosci.html
bad0a36bb0768f48d60b3d8c22f1af131079c0711c9ba63ffb961cc118bd0fee  design/reference-html/activefit-admin-bez-edycji-tresci-strony.html
7bed2d39d6aa371fdb50f8d38a4b8801774795183b8799a3454a193ca5278bc3  design/reference-html/activefit-admin-cennik-responsywny.html
7cc946be287bb91446b826b7d6dfe182122aee37b94516e87245483c8723dff0  design/reference-html/activefit-admin-galeria-uproszczona.html
bab7db66902739e91754466b3b9c5c8768dd8d63a026b921e575ef2666801866  design/reference-html/activefit-admin-regulamin-wersje.html
765505c5c5b8801b0fb53adc7c1ecc224fc9bc9cb749530a60cec5acb06b1384  design/reference-html/activefit-aktualnosci.html
19c03780567f79d489a7d1400e88e6f4c4309e9a388ca68cae10c5f5dc37f775  design/reference-html/activefit-cennik.html
76ebeae8836aa9e2beb5066527f1ab5f4d78849ce940568722abf781fdd6286b  design/reference-html/activefit-galeria.html
f6338e3c33488b4b502c3a383a38439d199690166a7b5cb25ac10cc56c79fdac  design/reference-html/activefit-kontakt-.html
a905fe14ec909c3d98fdd2aa62b03236e79a1e2df4e94302be8c1218a0e0cb85  design/reference-html/activefit-regulamin.html
79a73734ebb44473ffcf95b53ef4753cbb08bca0606673830c131de4a6517e3c  design/reference-html/activefit-start.html
bfb9bfa32b0cccf6c49a63a5a50b4f1bbcf726be485f3ce8876c0f7e4fc07e5e  design/reference-html/activefit-zakup-karnetu.html
4f580ab693b3f15629eaf1373c5c6b9e23c8e225830c725fd327d80991a41ddf  docs/architecture/business-flows.md
385a711323a383f150e982190f1907b2c4b4b99fc89c2fca9c4e8b089a9afa6b  docs/architecture/cms.md
0d43bca59fa0d096e5c1686f42ba2218edbd4bc145b7bc29697a9c868228003a  docs/architecture/database.md
da93eacd1311e50efed7c13da833dada6275ec3bc9b684069d74d8099815df7c  docs/architecture/decisions/ADR-001-activation.md
602e83c29a12d7d84ed74eedfc88af3ad6c52610b168ed82df9751a8c5b77ca8  docs/architecture/frontend.md
f4d8c553e2cfc881ae52ca28cc912edea5bebcc1bdafcd93d618684eb2775e97  docs/architecture/overview.md
9bdf46ffdd99dafc32eac702036108a80828049bb130afc8eb6c029b3af93553  docs/architecture/repository-setup.md
b981edd122256101e5bc2070386da443955103cc98e0fd548788b041a7e9715f  docs/architecture/security.md
2d28c8147d39c7cd06dca1f37abe3cbe3f16fa32e03ec887ae9742ecb3c0fff4  docs/development/implementation-plan.md
79219ebc755e7176832d3cd02563716a98f30dcfeff2b4a04727820bb8aed592  docs/development/testing-strategy.md
f3a854c4d07c353cca21a06077eff90bd00bf3916951972b7947f7d20bf9c720  docs/product/requirements.md
```
