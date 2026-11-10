import { readFileSync } from "node:fs";

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), "utf8");
const expect = (condition, message) => {
  if (!condition) throw new Error(`Kontrak Xendit gagal: ${message}`);
};

const routes = read("bimbelku-backend/routes/api.php");
const controller = read("bimbelku-backend/app/Http/Controllers/Api/XenditPaymentController.php");
const service = read("bimbelku-backend/app/Services/XenditPaymentService.php");
const settlement = read("bimbelku-backend/app/Services/GatewayPaymentSettlementService.php");
const moneyService = read("bimbelku-backend/app/Services/XenditMoneyMovementService.php");
const webhookService = read("bimbelku-backend/app/Services/XenditWebhookService.php");
const admin = read("bimbelku-backend/app/Http/Controllers/Api/AdminController.php");
const refunds = read("bimbelku-backend/app/Http/Controllers/Api/SessionWorkflowController.php");
const page = read("src/pages/pembayaran/PaymentPage.tsx");
const env = read("bimbelku-backend/.env.example");

expect(routes.includes("/webhooks/xendit"), "webhook publik tersedia");
expect(routes.includes("/xendit-session"), "checkout murid tersedia");
expect(controller.includes("x-callback-token") && controller.includes("hash_equals"), "token webhook diverifikasi timing-safe");
expect(controller.includes("PaymentGatewayEvent::firstOrCreate"), "webhook diproses idempoten");
expect(service.includes("/sessions") && service.includes("PAYMENT_LINK"), "Payment Sessions hosted checkout digunakan");
expect(controller.includes("app(GatewayPaymentSettlementService::class)->settle") && settlement.includes("gateway_payment_request_id"), "Payment Request ID disimpan oleh settlement bersama untuk refund");
expect(moneyService.includes("/refunds") && moneyService.includes("payment_request_id"), "refund dikirim ke metode pembayaran asal melalui Xendit");
expect(moneyService.includes("/v3/payouts") && env.includes("MONEY-OUT"), "payout tutor memakai Xendit Payout v3 dan izin MONEY-OUT didokumentasikan");
expect(webhookService.includes("refund.succeeded") && webhookService.includes("v3_payout.succeeded"), "webhook refund dan payout mengubah status final");
expect(admin.includes("createPayout") && !admin.includes("'proof_file' => 'required|image"), "admin tidak lagi mengunggah bukti transfer payout manual");
expect(refunds.includes("createRefund") && refunds.includes("RefundStatusPresenter") && refunds.includes("], 202);"), "refund admin menunggu status final Xendit");
expect(!page.includes('type="file"') || page.includes('settings.provider === "xendit"'), "Xendit menjadi pengalaman pembayaran utama");
expect(env.includes("XENDIT_SECRET_KEY=") && env.includes("XENDIT_WEBHOOK_TOKEN="), "konfigurasi server didokumentasikan");

console.log("Kontrak Xendit lulus: checkout, refund, payout tutor, webhook idempoten, dan konfigurasi server tersedia.");
