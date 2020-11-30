<?php

namespace App\Services\Import;

use App\Models\PaymentGateway;
use App\Models\PaymentTransaction;
use App\Models\PaymentTransactionStatus;
use App\Repositories\ChargebacksRepository;
use App\Transformers\PaymentGateways\ImportChargebacks\Ebanx;
use App\Transformers\PaymentGateways\ImportChargebacks\ImportChargebacksTransformer;
use App\Transformers\PaymentGateways\ImportChargebacks\Checkout;
use App\Transformers\PaymentGateways\ImportChargebacks\PayPal;
use App\Transformers\PaymentGateways\ImportChargebacks\Stripe;
use Excel;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Readers\LaravelExcelReader;
use Exception;

class Chargebacks
{
    protected $transformers = [
        PaymentGateway::CHECKOUT => Checkout::class,
        PaymentGateway::EBANX    => Ebanx::class,
        PaymentGateway::PAYPAL   => PayPal::class,
        PaymentGateway::STRIPE   => Stripe::class,
    ];

    /**
     * @param UploadedFile   $file
     * @param PaymentGateway $paymentGateway
     * @return string
     */
    public function import(UploadedFile $file, PaymentGateway $paymentGateway): string
    {
        $i = 0;

        $report = [];

        Excel::load($file->getRealPath(), function (LaravelExcelReader $reader) use (&$i, &$report, $paymentGateway) {

            $transformer = $this->getTransformer($paymentGateway);

            $repository = new ChargebacksRepository;

            foreach ($transformer->transform(collect($reader->toArray())) as $transactionId) {

                $transaction = PaymentTransaction::where('transaction_id', $transactionId)->first();

                if (!$transaction) {
                    array_push($report, [
                        'type'    => "danger",
                        'message' => "Transaction $transactionId is not found"
                    ]);
                    continue;
                }

                $repository->store($transaction, $transaction->price, $transaction->currency, null);

                $transaction->update([
                    'status' => PaymentTransactionStatus::CHARGEBACK
                ]);

                $i++;
            }
        });

        array_unshift($report, [
            "type"    => "success",
            "message" => "Marked as chargebacks - {$i}",
        ]);

        return $this->formatReport($report);
    }

    /**
     * @param array $report
     * @return string
     */
    private function formatReport(array $report): string
    {
        return array_reduce($report, function ($prev, $item) {
            $alertContent = view("crm.partials.alert", [
                "type"    => $item['type'],
                "message" => $item['message'],
            ])->render();
            return $prev . $alertContent;
        });
    }

    /**
     * @param PaymentGateway $paymentGateway
     * @return ImportChargebacksTransformer
     * @throws Exception
     */
    private function getTransformer(PaymentGateway $paymentGateway): ImportChargebacksTransformer
    {
        if (!isset($this->transformers[$paymentGateway->id])) {
            throw new Exception('Incorrect payment gateway');
        }

        return new $this->transformers[$paymentGateway->id];
    }
}