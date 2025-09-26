function importShopifyOrdersUnshippedPaypalLast3Hours() {
    const shopName = 'lanius-gmbh';
    const accessToken = 'XXX';
    const apiVersion = '2025-01';

    // Berechnen Sie das Datum und die Uhrzeit vor 3 Stunden
    const now = new Date();
    const threeHoursAgo = new Date(now.getTime() - 3 * 60 * 60000); // 3 Stunden in Millisekunden
    const createdAtMin = threeHoursAgo.toISOString();

    const shopifyUrl = `https://${shopName}.myshopify.com/admin/api/${apiVersion}/orders.json?status=open&created_at_min=${createdAtMin}`;

    try {
        const shopifyResponse = UrlFetchApp.fetch(shopifyUrl, {
            method: 'get',
            headers: {
                'X-Shopify-Access-Token': accessToken,
                'Content-Type': 'application/json'
            }
        });

        const shopifyJson = shopifyResponse.getContentText();
        const shopifyOrders = JSON.parse(shopifyJson).orders;

        const ss = SpreadsheetApp.getActiveSpreadsheet();
        let sheet = ss.getSheetByName("ShopifyOrders");
        if (!sheet) {
            sheet = ss.insertSheet("ShopifyOrders");
        }
        sheet.clearContents();

        sheet.appendRow(['Order Name', 'Payment Method', 'Order ID', 'Method of Payment ID']); // Geändert

        // PlentyOne API-Daten abrufen
        const plentyOrders = getPlentyOrders();

        shopifyOrders.forEach(shopifyOrder => {
            // Überprüfen, ob die Bestellung unversendet oder teilweise versendet ist
            if (shopifyOrder.fulfillment_status === null || shopifyOrder.fulfillment_status === 'partial') {
                // Überprüfen, ob die Bestellung mit PayPal bezahlt wurde
                if (shopifyOrder.payment_gateway_names && shopifyOrder.payment_gateway_names.includes('paypal')) {
                    const paymentMethods = shopifyOrder.payment_gateway_names ? shopifyOrder.payment_gateway_names.join(' + ') : 'N/A';
                    const methodOfPaymentId = findMethodOfPaymentId(shopifyOrder.id, plentyOrders); // Geändert
                    sheet.appendRow([shopifyOrder.name, paymentMethods, shopifyOrder.id, methodOfPaymentId]); // Geändert
                }
            }
        });

        Logger.log("Shopify Bestellungen (Unversendet/Teilversendet, PayPal, Letzte 3 Stunden) erfolgreich importiert!");
    } catch (e) {
        Logger.log("Fehler beim Abrufen der Shopify Bestellungen: " + e.message);
    }
}