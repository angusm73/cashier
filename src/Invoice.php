<?php

namespace Laravel\Cashier;

use Carbon\Carbon;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\View;
use Stripe\Invoice as StripeInvoice;
use Symfony\Component\HttpFoundation\Response;

class Invoice
{
    /**
     * The Stripe model instance.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $owner;

    /**
     * The Stripe invoice instance.
     *
     * @var \Stripe\Invoice
     */
    protected $invoice;

    /**
     * Create a new invoice instance.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $owner
     * @param  \Stripe\Invoice  $invoice
     * @return void
     */
    public function __construct($owner, StripeInvoice $invoice)
    {
        $this->owner = $owner;
        $this->invoice = $invoice;
    }

    /**
     * Get a Carbon date for the invoice.
     *
     * @param  \DateTimeZone|string  $timezone
     * @return \Carbon\Carbon
     */
    public function date($timezone = null)
    {
        $carbon = Carbon::createFromTimestampUTC($this->invoice->created ?? $this->invoice->date);

        return $timezone ? $carbon->setTimezone($timezone) : $carbon;
    }

    /**
     * Get a Carbon date for the invoice due date.
     *
     * @param  \DateTimeZone|string  $timezone
     * @return \Carbon\Carbon
     */
    public function dueDate($timezone = null)
    {
        if (is_null($this->invoice->due_date)) {
            return null;
        }

        $carbon = Carbon::createFromTimestampUTC($this->invoice->due_date);

        return $timezone ? $carbon->setTimezone($timezone) : $carbon;
    }

    /**
     * Get a Carbon date for the invoice next attempt date.
     *
     * @param  \DateTimeZone|string  $timezone
     * @return \Carbon\Carbon
     */
    public function nextAttemptDate($timezone = null)
    {
        $carbon = Carbon::createFromTimestampUTC($this->invoice->next_payment_attempt);

        return $timezone ? $carbon->setTimezone($timezone) : $carbon;
    }

    /**
     * Check invoice is unpaid and past due date.
     *
     * @param  \DateTimeZone|string  $timezone
     * @return boolean
     */
    public function pastDue($timezone = null)
    {
        if (is_null($this->invoice->due_date)) {
            return false;
        }

        return !$this->invoice->paid
            && (
                ($this->invoice->attempted && $this->invoice->attempt_count > 0)
                || $this->dueDate($timezone)->isPast());
    }

    /**
     * Get the total amount that was paid (or will be paid).
     *
     * @return string
     */
    public function total()
    {
        return $this->formatAmount($this->rawTotal());
    }

    /**
     * Get the raw total amount that was paid (or will be paid).
     *
     * @return int
     */
    public function rawTotal()
    {
        return $this->invoice->total + $this->rawStartingBalance();
    }

    /**
     * Get the total of the invoice (before discounts).
     *
     * @return string
     */
    public function subtotal()
    {
        return $this->formatAmount($this->invoice->subtotal);
    }

    /**
     * Determine if the account had a starting balance.
     *
     * @return bool
     */
    public function hasStartingBalance()
    {
        return $this->rawStartingBalance() < 0;
    }

    /**
     * Get the starting balance for the invoice.
     *
     * @return string
     */
    public function startingBalance()
    {
        return $this->formatAmount($this->rawStartingBalance());
    }

    /**
     * Determine if the account had a balance from credit notes.
     *
     * @return bool
     */
    public function hasCreditBalance()
    {
        return $this->rawCreditBalance() > 0;
    }

    /**
     * Get the balance from credit notes for the invoice.
     *
     * @return string
     */
    public function creditBalance()
    {
        return $this->formatAmount($this->rawCreditBalance());
    }

    /**
     * Determine if the invoice has a discount.
     *
     * @return bool
     */
    public function hasDiscount()
    {
        return $this->invoice->subtotal > 0 &&
            $this->invoice->subtotal != $this->invoice->total &&
            !is_null($this->invoice->discount);
    }

    /**
     * Get the discount amount.
     *
     * @return string
     */
    public function discount()
    {
        return $this->formatAmount($this->invoice->subtotal + $this->invoice->tax - $this->invoice->total);
    }

    /**
     * Get the coupon code applied to the invoice.
     *
     * @return string|null
     */
    public function coupon()
    {
        if (isset($this->invoice->discount)) {
            return $this->invoice->discount->coupon->id;
        }
    }

    /**
     * Determine if the discount is a percentage.
     *
     * @return bool
     */
    public function discountIsPercentage()
    {
        return $this->coupon() && isset($this->invoice->discount->coupon->percent_off);
    }

    /**
     * Get the discount percentage for the invoice.
     *
     * @return int
     */
    public function percentOff()
    {
        if ($this->coupon()) {
            return $this->invoice->discount->coupon->percent_off;
        }

        return 0;
    }

    /**
     * Get the discount amount for the invoice.
     *
     * @return string
     */
    public function amountOff()
    {
        if (isset($this->invoice->discount->coupon->amount_off)) {
            return $this->formatAmount($this->invoice->discount->coupon->amount_off);
        }

        return $this->formatAmount(0);
    }

    /**
     * Get the tax total amount.
     *
     * @return string
     */
    public function tax()
    {
        return $this->formatAmount($this->invoice->tax);
    }

    /**
     * Get all of the "invoice item" line items.
     *
     * @return array
     */
    public function invoiceItems()
    {
        return $this->invoiceItemsByType('invoiceitem');
    }

    /**
     * Get all of the "subscription" line items.
     *
     * @return array
     */
    public function subscriptions()
    {
        return $this->invoiceItemsByType('subscription');
    }

    /**
     * Get all of the invoice items by a given type.
     *
     * @param  string  $type
     * @return array
     */
    public function invoiceItemsByType($type)
    {
        $lineItems = [];

        if (isset($this->lines->data)) {
            foreach ($this->lines->data as $line) {
                if ($line->type == $type) {
                    $lineItems[] = new InvoiceItem($this->owner, $line);
                }
            }
        }

        return $lineItems;
    }

    /**
     * Format the given amount into a displayable currency.
     *
     * @param  int  $amount
     * @return string
     */
    protected function formatAmount($amount)
    {
        return Cashier::formatAmount($amount, $this->invoice->currency);
    }

    /**
     * Get the View instance for the invoice.
     *
     * @param  array  $data
     * @return \Illuminate\Contracts\View\View
     */
    public function view(array $data)
    {
        return View::make('cashier::receipt', array_merge($data, [
            'invoice' => $this,
            'owner' => $this->owner,
            'user' => $this->owner,
        ]));
    }

    /**
     * Capture the invoice as a PDF and return the raw bytes.
     *
     * @param  array  $data
     * @return string
     */
    public function pdf(array $data)
    {
        if (!defined('DOMPDF_ENABLE_AUTOLOAD')) {
            define('DOMPDF_ENABLE_AUTOLOAD', false);
        }

        $dompdf = new Dompdf;
        $dompdf->loadHtml($this->view($data)->render());
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Create an invoice download response.
     *
     * @param  array  $data
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function download(array $data)
    {
        $filename = $data['product'] . '_' . $this->date()->month . '_' . $this->date()->year . '.pdf';

        return new Response($this->pdf($data), 200, [
            'Content-Description' => 'File Transfer',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Transfer-Encoding' => 'binary',
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Get the raw starting balance for the invoice.
     *
     * @return float
     */
    public function rawStartingBalance()
    {
        return isset($this->invoice->starting_balance)
            ? $this->invoice->starting_balance
            : 0;
    }

    /**
     * Get the raw balance applied using credit notes for the invoice.
     *
     * @return float
     */
    public function rawCreditBalance()
    {
        return (isset($this->invoice->pre_payment_credit_notes_amount) ? $this->invoice->pre_payment_credit_notes_amount : 0)
            + (isset($this->invoice->post_payment_credit_notes_amount) ? $this->invoice->post_payment_credit_notes_amount : 0);
    }

    /**
     * Get event history for this Invoice
     *
     * @return \Illuminate\Support\Collection
     */
    public function history()
    {
        $event_history = collect();

        $payments = $this->paymentIntents();
        foreach ($payments as $pi) {
            $charges = $pi->charges->all();
            if (count($charges->data)) {
                foreach ($charges as $ch) {
                    if ($ch->status == 'failed') {
                        $ch->color = "danger";
                    }
                    $event_history->push($ch);
                }
            } else {
                if ($pi->status == 'requires_payment_method') {
                    $pi->color = "danger";
                }
                $event_history->push($pi);
            }
        }

        foreach (collect($this->invoice->status_transitions)->reverse() as $status => $time) {
            if (!is_null($time)) {
                $status = $status === 'finalized_at' && $this->invoice->paid ? 'created_at' : $status;
                $color = $status === 'paid_at' && $this->invoice->paid ? 'success' : ($status == 'voided_at' || $status == 'marked_uncollectible_at' ? 'warning' : 'secondary');
                $event_history->push([
                    'created' => $time,
                    'description' => "Invoice was " . str_replace('_', ' ', rtrim($status, "_at")),
                    'object' => "status_transition",
                    'color' => $color,
                ]);
            }
        }

        return $event_history->sortByDesc('created');
    }

    /**
     * Get the payment intents for this Invoice
     *
     * @return \Illuminate\Support\Collection
     */
    public function paymentIntents()
    {
        $payments = \Stripe\PaymentIntent::all(['customer' => $this->owner->stripe_id], Cashier::stripeOptions());
        $invoice = $this->invoice;
        return collect($payments->data)->filter(function ($pi) use ($invoice) {
            return $pi->invoice === $invoice->id;
        });
    }

    /**
     * Get the Stripe invoice instance.
     *
     * @return \Stripe\Invoice
     */
    public function asStripeInvoice()
    {
        return $this->invoice;
    }

    /**
     * Dynamically get values from the Stripe invoice.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->invoice->{$key};
    }
}
