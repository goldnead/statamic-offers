<?php

return [

    'permission' => 'Angebots-Einstellungen verwalten',

    'groups' => [
        'seller' => [
            'title' => 'Verkäufer',
            'description' => 'Wer im Widerrufstext als Vertragspartner steht. Bleiben beide Felder leer, setzt das Addon den Namen der Anwendung und die Absenderadresse des Mailversands ein.',
        ],
        'withdrawal' => [
            'title' => 'Widerruf',
            'description' => 'Die Vorgabe, die ein Angebot erbt, solange es nichts Eigenes sagt. Ein Angebot mit eigenem Text behält seinen. Bereits bezahlte Käufe behalten den Wortlaut, dem der Käufer damals zugestimmt hat — er ist mit der Zahlung eingefroren. Die Felder, die der Bezahlvorgang beim Käufer abfragt, stehen weiterhin in der Datei unter „checkout_fields“: eine Feldbibliothek mit Beschriftung, Typ und Regeln je Eintrag passt in kein einzelnes Formularfeld.',
        ],
        'display' => [
            'title' => 'Anzeige',
            'description' => 'Was passiert, wenn eine Vorlage ein Angebot ausgibt.',
        ],
    ],

    'fields' => [
        'seller_name' => [
            'label' => 'Name',
            'description' => 'Steht im Widerrufstext überall dort, wo {seller_name} vorkommt. Leer heißt: der Name der Anwendung.',
        ],
        'seller_contact' => [
            'label' => 'Kontakt',
            'description' => 'Wohin ein Widerruf geschickt wird, im Text als {seller_contact}. Leer heißt: die Absenderadresse des Mailversands.',
        ],
        'withdrawal_days' => [
            'label' => 'Widerrufsfrist in Tagen',
            'description' => 'Die Frist, die ein neues Angebot erbt. Sie ersetzt auch {days} im Text.',
        ],
        'withdrawal_text' => [
            'label' => 'Widerrufsbelehrung',
            'description' => 'Der Wortlaut, den ein Käufer vor dem Bezahlen liest, solange das Angebot keinen eigenen trägt. Eine Änderung gilt ab dem nächsten Kauf; frühere Käufe behalten ihren Stand.',
        ],
        'withdrawal_waiver_text' => [
            'label' => 'Verzichtserklärung',
            'description' => 'Der Satz, dem ein Käufer digitaler Inhalte vor der Auslieferung zustimmt (§ 356 Abs. 5 BGB). Er wird mit der Zahlung eingefroren, eine Änderung schreibt also keine alte Zustimmung um.',
        ],
        'withdrawal_b2b_text' => [
            'label' => 'Hinweis für Geschäftskäufer',
            'description' => 'Wird zusätzlich angezeigt, wenn der Käufer als Unternehmen kauft. Leer heißt: es wird keiner angezeigt.',
        ],
        'withdrawal_checkbox_required' => [
            'label' => 'Häkchen verlangen',
            'description' => 'An: der Bezahlvorgang geht ohne gesetztes Häkchen nicht weiter. Aus ergibt nur Sinn, wenn erst nach Ablauf der Frist geliefert wird.',
        ],
        'count_impressions' => [
            'label' => 'Einblendungen zählen',
            'description' => 'An: jede Ausgabe über {{ offers:show }} erhöht die gezeigte Zahl. Aus bleibt sie bei null, die Annahmequote wird damit unbrauchbar — sinnvoll auf stark gecachten Seiten, wo sie ohnehin nichts aussagt.',
        ],
    ],

];
