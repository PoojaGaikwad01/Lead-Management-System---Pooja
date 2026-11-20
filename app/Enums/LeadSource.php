<?php

namespace App\Enums;

enum LeadSource: string
{
    case FbLeadAd = 'fb_lead_ad';
    case IgLeadAd = 'ig_lead_ad';
    case Messenger = 'messenger';
    case IgDm = 'ig_dm';
    case WhatsappCustomer = 'whatsapp_customer';
    case WhatsappAgency = 'whatsapp_agency';

    public function label(): string
    {
        return match ($this) {
            self::FbLeadAd => 'Facebook Lead Ad',
            self::IgLeadAd => 'Instagram Lead Ad',
            self::Messenger => 'Messenger',
            self::IgDm => 'Instagram DM',
            self::WhatsappCustomer => 'WhatsApp',
            self::WhatsappAgency => 'Agency (WhatsApp)',
        };
    }

    public function short(): string
    {
        return match ($this) {
            self::FbLeadAd => 'FB Ad',
            self::IgLeadAd => 'IG Ad',
            self::Messenger => 'Messenger',
            self::IgDm => 'IG DM',
            self::WhatsappCustomer => 'WhatsApp',
            self::WhatsappAgency => 'Agency',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::FbLeadAd => 'bg-blue-100 text-blue-700',
            self::IgLeadAd => 'bg-pink-100 text-pink-700',
            self::Messenger => 'bg-sky-100 text-sky-700',
            self::IgDm => 'bg-fuchsia-100 text-fuchsia-700',
            self::WhatsappCustomer => 'bg-green-100 text-green-700',
            self::WhatsappAgency => 'bg-teal-100 text-teal-700',
        };
    }

    /** Messaging channel used for message rows / replies; null for Lead Ads (no chat thread). */
    public function channel(): ?string
    {
        return match ($this) {
            self::FbLeadAd, self::IgLeadAd => null,
            self::Messenger => 'messenger',
            self::IgDm => 'instagram',
            self::WhatsappCustomer, self::WhatsappAgency => 'whatsapp',
        };
    }

    public function isLeadAd(): bool
    {
        return $this->channel() === null;
    }
}
