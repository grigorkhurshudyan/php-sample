<?php

namespace App\Services\Import;

use App\Events\Orders\MarkAsShipped;
use App\Models\CurrencyExchangeRate;
use App\Models\Order;
use App\Models\OrderShipmentStatus;
use App\Models\TrackingNumber;
use Carbon\Carbon;
use Excel;
use Illuminate\Database\Eloquent\Collection;
use Maatwebsite\Excel\Readers\LaravelExcelReader;
use Illuminate\Support\Collection as SupportCollection;

class ShippedOrders
{
    /**
     * @param string $filePath
     * @return string
     */
    public function import(string $filePath): string
    {
        $orderTrackingNumbers = $this->getOrderTrackingNumbers($filePath);

        $importedOrders = $this->importTrackingNumbers($orderTrackingNumbers);

        $missingOrders = $this->getMissingOrders(array_keys($orderTrackingNumbers), $importedOrders->pluck('reference_id')->toArray());

        return $this->createReport($missingOrders, $importedOrders->count());
    }

    /**
     * @param string $filePath
     * @return array
     */
    private function getOrderTrackingNumbers(string $filePath): array
    {
        $orderTrackingNumbers = [];

        $rate = CurrencyExchangeRate::getRateForCurrency(config('wlm.shipping_price_currency'));

        Excel::load($filePath, function (LaravelExcelReader $reader) use (&$orderTrackingNumbers, $rate) {

            foreach ($reader->toArray() as $key => $row) {

                if (!isset($row['order_id']) || !isset($row['tracking_number'])) {
                    continue;
                }

                $orderId = $this->formatOrderId($row['order_id']);

                $orderTrackingNumbers[$orderId] = [
                    'tracking_number' => $row['tracking_number'],
                    'shipping_price'  => $this->getShippingPrice($row, $rate),
                    'carrier_name' => $row['carrier_name']
                ];

            }
        });

        return $orderTrackingNumbers;
    }

    /**
     * @param array $row
     * @param       $rate
     * @return float|int
     */
    private function getShippingPrice(array $row, $rate)
    {
        if (empty($row['shipping_price'])) {
            return 0;
        }

        return round($row['shipping_price'] / $rate, 2);
    }

    /**
     * @param $orderId
     * @return int|string
     */
    private function formatOrderId($orderId)
    {
        if (is_string($orderId)) {
            return $orderId;
        }
        return intval($orderId);
    }

    /**
     * @param array $orderTrackingNumbers
     * @return Collection
     */
    private function importTrackingNumbers(array $orderTrackingNumbers): Collection
    {
        return Order::with('trackingNumbers', 'customer')
            ->whereIn('reference_id', array_keys($orderTrackingNumbers))
            ->get()
            ->map(function (Order $order) use ($orderTrackingNumbers) {

                $order->trackingNumbers()->saveMany($this->getTrackingNumbers($order, $orderTrackingNumbers[$order->reference_id]['tracking_number'], $orderTrackingNumbers[$order->reference_id]['carrier_name']));

                $order->shipping_price = $orderTrackingNumbers[$order->reference_id]['shipping_price'];

                $order->shipped = OrderShipmentStatus::SHIPPED;

                $order->shipment_date = Carbon::now();

                $order->save();

                $order->logModelEvent('Set shipment status as: ' . $order->shipmentStatus->name);

                event(new MarkAsShipped($order));

                return $order;

            });
    }

    /**
     * @param array $orderTrackingNumbers
     * @param array $importedOrders
     * @return array
     */
    private function getMissingOrders(array $orderTrackingNumbers, array $importedOrders): array
    {
        return array_diff($orderTrackingNumbers, $importedOrders);
    }

    /**
     * @param Order  $order
     * @param string $cellData
     * @return Collection
     */
    private function getTrackingNumbers(Order $order, string $cellData, string $carrierName): SupportCollection
    {
        $formattedString = preg_replace("/[^,\w]+/", "", $cellData);

        $trackingNumbersValues = explode(",", $formattedString);

        return collect($trackingNumbersValues)->map(function ($trackingNumberValue) use ($order, $carrierName) {
            if ($order->trackingNumbers->contains('tracking_number', $trackingNumberValue)) {
                return false;
            }
            return new TrackingNumber([
                'tracking_number' => $trackingNumberValue,
                'carrier_name' => $carrierName
            ]);
        })
            ->filter()
            ->unique('tracking_number');
    }

    /**
     * @param $missingOrders
     * @param $numberImportedOrders
     * @return string
     */
    private function createReport($missingOrders, $numberImportedOrders): string
    {
        $report = [];

        foreach ($missingOrders as $missingOrder) {

            array_push($report, [
                'type'    => "danger",
                'message' => "Order #{$missingOrder} is not found"
            ]);

        }

        array_unshift($report, [
            "type"    => "success",
            "message" => "Marked as shipped - {$numberImportedOrders}",
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
}