<?php
ob_start();

define("API_KEY", "8820235292:AAFHfB9Zv0bT0cfOuuCbPvb0MThPmW5LSIU");
$admin = "ADMIN_ID"; // O'z Telegram user ID raqamingizni yozing (masalan: 123456789)

/*
@TexnoHelpBot v0.0.3
Manba: @Org_Coder | Yangilandi
Reply Keyboard menyu qo'shildi
*/

// ===== Sozlamalar va ma'lumotlar papkasi =====
if (!is_dir(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0755, true);
}

function getSettings($chat_id) {
    $file = __DIR__ . "/data/settings_$chat_id.json";
    if (file_exists($file)) {
        return json_decode(file_get_contents($file), true) ?: [];
    }
    return [];
}

function saveSettings($chat_id, $data) {
    $file = __DIR__ . "/data/settings_$chat_id.json";
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function getPremiumList($chat_id) {
    $file = __DIR__ . "/data/premium_$chat_id.json";
    if (file_exists($file)) {
        return json_decode(file_get_contents($file), true) ?: [];
    }
    return [];
}

function savePremiumList($chat_id, $list) {
    $file = __DIR__ . "/data/premium_$chat_id.json";
    file_put_contents($file, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Adminlarni tekshirib premium ro'yxatni yangilash
function refreshPremiumFromAdmins($chat_id) {
    $admins = bot('getChatAdministrators', ['chat_id' => $chat_id]);
    if (!$admins || !isset($admins->result) || !is_array($admins->result)) {
        return getPremiumList($chat_id);
    }

    $prem_list = getPremiumList($chat_id);

    foreach ($admins->result as $member) {
        $user = $member->user ?? null;
        if (!$user || ($user->is_bot ?? false)) continue;

        $uid = $user->id;
        $is_prem = $user->is_premium ?? false;

        if ($is_prem) {
            $prem_list[$uid] = [
                'id'        => $uid,
                'name'      => $user->first_name ?? '',
                'username'  => $user->username ?? '',
                'last_seen' => time()
            ];
        }
    }

    savePremiumList($chat_id, $prem_list);
    return $prem_list;
}

function getGroups() {
    $file = __DIR__ . "/data/groups.json";
    if (file_exists($file)) {
        return json_decode(file_get_contents($file), true) ?: [];
    }
    return [];
}

function saveGroups($list) {
    $file = __DIR__ . "/data/groups.json";
    file_put_contents($file, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function getUserState($user_id) {
    $file = __DIR__ . "/data/state_$user_id.json";
    if (file_exists($file)) {
        return json_decode(file_get_contents($file), true) ?: [];
    }
    return [];
}

function saveUserState($user_id, $data) {
    $file = __DIR__ . "/data/state_$user_id.json";
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function clearUserState($user_id) {
    $file = __DIR__ . "/data/state_$user_id.json";
    if (file_exists($file)) unlink($file);
}

function bot($method, $datas = []) {
    $url = "https://api.telegram.org/bot" . API_KEY . "/" . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res);
}

function isAdmin($chat_id, $user_id) {
    $status = bot('getChatMember', [
        'chat_id' => $chat_id,
        'user_id' => $user_id
    ])->result->status ?? '';
    return in_array($status, ['administrator', 'creator']);
}

// Menyu faqat asosiy admin yoki bot turgan guruh adminlariga
function canUseMenu($user_id) {
    global $admin;
    if ($admin && $admin !== "ADMIN_ID" && (string)$user_id === (string)$admin) {
        return true;
    }
    $groups = getGroups();
    foreach ($groups as $gid => $info) {
        if (isAdmin($gid, $user_id)) {
            return true;
        }
    }
    return false;
}

function removeKeyboard() {
    return json_encode([
        'remove_keyboard' => true
    ]);
}

// ===== Asosiy Reply Keyboard menyu =====
function mainMenu() {
    return json_encode([
        'keyboard' => [
            [
                ['text' => "✅ Zayavka tasdiqlash"],
                ['text' => "➕ Guruh qo'shish"]
            ],
            [
                ['text' => "📋 Guruhlarim"],
                ['text' => "📢 Kanal ulash"]
            ],
            [
                ['text' => "⭐ Premium a'zolar"]
            ]
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => false
    ]);
}

// ===== So'kinish va taqiqlangan so'zlar =====
$sokinish_royhati = [
    "kot", "am", "om", "Onangni ami", "onangni ami", "oneni ami", "oneni omi",
    "yban", "Yiban", "Dalbayob", "dalbayob", "Dnx", "Kot", "Suka", "suka",
    "Jalab", "Jala", "jalab", "jala", "Jalla", "jalla", "K*t", "k*t",
];

$taqiqlangan_royhati = [
    "lich", "Lich", "sotaman", "Sotaman",
];

// ===== Update ni olish =====
$update = json_decode(file_get_contents("php://input"));
$bot = bot('getMe')->result->username;
$bot_id = bot('getMe')->result->id;

// ==================== 1. JOIN REQUEST (Zayafka) AVTOMATIK TASDIQLASH ====================
if (isset($update->chat_join_request)) {
    $req = $update->chat_join_request;
    $chat_id = $req->chat->id;
    $user_id = $req->from->id;

    $settings = getSettings($chat_id);
    // Default true, agar o'chirilgan bo'lmasa tasdiqlaydi
    $auto = $settings['auto_approve'] ?? true;

    if ($auto) {
        bot('approveChatJoinRequest', [
            'chat_id' => $chat_id,
            'user_id' => $user_id
        ]);
    }
    exit;
}

// ==================== 2. CALLBACK (Tekshirish tugmasi) ====================
if (isset($update->callback_query)) {
    $cb = $update->callback_query;
    $cb_data = $cb->data;
    $cb_cid = $cb->message->chat->id;
    $cb_uid = $cb->from->id;
    $cb_mid = $cb->message->message_id;

    if ($cb_data == "check_sub") {
        $settings = getSettings($cb_cid);
        if (empty($settings['forced_channel'])) {
            bot('answerCallbackQuery', [
                'callback_query_id' => $cb->id,
                'text' => "Majburiy obuna o'rnatilmagan",
                'show_alert' => true
            ]);
            exit;
        }

        $member = bot('getChatMember', [
            'chat_id' => $settings['forced_channel'],
            'user_id' => $cb_uid
        ]);
        $status = $member->result->status ?? 'left';

        if (in_array($status, ['member', 'administrator', 'creator'])) {
            bot('answerCallbackQuery', [
                'callback_query_id' => $cb->id,
                'text' => "✅ Obuna bo'lgansiz! Endi yozishingiz mumkin.",
                'show_alert' => true
            ]);
            bot('deleteMessage', [
                'chat_id' => $cb_cid,
                'message_id' => $cb_mid
            ]);
        } else {
            bot('answerCallbackQuery', [
                'callback_query_id' => $cb->id,
                'text' => "❌ Hali obuna bo'lmadingiz!",
                'show_alert' => true
            ]);
        }
    }
    exit;
}

// ==================== 3. MESSAGE ISHLOV BERISH ====================
if (!isset($update->message)) exit;

$message   = $update->message;
$cid       = $message->chat->id;
$mid       = $message->message_id;
$name      = $message->from->first_name ?? "Foydalanuvchi";
$raw_text  = trim($message->text ?? "");
$text      = mb_strtolower($raw_text);
$fromid    = $message->from->id;
$is_premium = $message->from->is_premium ?? false;
$chat_type = $message->chat->type ?? 'private'; // private, group, supergroup

// Premium foydalanuvchini ro'yxatga olish
if ($is_premium && in_array($chat_type, ['group', 'supergroup'])) {
    $prem_list = getPremiumList($cid);
    if (!isset($prem_list[$fromid])) {
        $prem_list[$fromid] = [
            'id'         => $fromid,
            'name'       => $message->from->first_name ?? '',
            'username'   => $message->from->username ?? '',
            'last_seen'  => time()
        ];
        savePremiumList($cid, $prem_list);
    } else {
        $prem_list[$fromid]['last_seen'] = time();
        $prem_list[$fromid]['name'] = $message->from->first_name ?? $prem_list[$fromid]['name'];
        savePremiumList($cid, $prem_list);
    }
}

// ==================== YANGI A'ZO SALOMLASH ====================
if (isset($message->new_chat_members)) {
    foreach ($message->new_chat_members as $user) {
        if ($user->is_bot) continue;

        // Premium bo'lsa ro'yxatga qo'shamiz
        if (($user->is_premium ?? false) && in_array($chat_type, ['group', 'supergroup'])) {
            $prem_list = getPremiumList($cid);
            $prem_list[$user->id] = [
                'id'        => $user->id,
                'name'      => $user->first_name ?? '',
                'username'  => $user->username ?? '',
                'last_seen' => time()
            ];
            savePremiumList($cid, $prem_list);
        }

        if (isAdmin($cid, $user->id)) continue;

        $uname = $user->username ? "@{$user->username}" : ($user->first_name ?? "Foydalanuvchi");
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>👋 Assalomu alaykum {$uname}, guruhimizga xush kelibsiz!</b>",
            'parse_mode' => "HTML"
        ]);
    }
}

// ==================== BOT GURUHGA QO'SHILGANDA ====================
if (isset($message->new_chat_members)) {
    foreach ($message->new_chat_members as $user) {
        if ($user->id == $bot_id) {
            // Guruhni ro'yxatga olish
            $groups = getGroups();
            $groups[$cid] = [
                'id'    => $cid,
                'title' => $message->chat->title ?? 'Noma\'lum guruh',
                'type'  => $chat_type,
                'added' => time()
            ];
            saveGroups($groups);

            // Default sozlamalar
            $settings = getSettings($cid);
            if (!isset($settings['auto_approve'])) {
                $settings['auto_approve'] = true;
                saveSettings($cid, $settings);
            }

            bot('sendMessage', [
                'chat_id' => $cid,
                'text' => "<b>👥 Meni guruhga qo'shganingizdan xursandman!</b>

🤖 Ushbu bot yordamida guruhingizni tartibga solishingiz mumkin.

📚 <b>Asosiy imkoniyatlar:</b>
<blockquote>🫂 Yangi a'zolarni kutib oladi
🔞 So'kinishni o'chiradi + 1 daqiqa mute
⚠️ Taqiqlangan so'zlarni bloklaydi
📌 Majburiy obuna sozlash mumkin
✅ Zayafkalarni avtomatik tasdiqlaydi</blockquote>

<b>Buyruqlar:</b>
<code>/mute 10 minute</code> — mute berish
<code>/unmute</code> — mutedan chiqarish
<code>/ulash @kanal 1h</code> — vaqtli majburiy obuna
<code>/setobuna @kanal</code> — doimiy majburiy obuna
<code>/delobuna</code> — majburiy obunani o'chirish
<code>/obuna</code> — hozirgi majburiy kanal

♻️ <b>Versiya:</b> v0.0.3",
                'parse_mode' => "HTML",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => "➕ Guruhga qo'shish", 'url' => "https://t.me/$bot?startgroup=new"]]
                    ]
                ])
            ]);
        }
    }
}

// ==================== FOYDALANUVCHI HOLATINI TEKSHIRISH (Kanal ulash uchun) ====================
$user_state = getUserState($fromid);
if (!empty($user_state['action']) && $user_state['action'] == 'wait_channel' && $chat_type == 'private') {
    $channel = $raw_text;
    if (strpos($channel, "t.me/") !== false) {
        $channel = preg_replace('#https?://t\.me/#i', '', $channel);
        $channel = explode("?", $channel)[0];
    }
    $channel = ltrim($channel, "@");

    if (empty($channel) || strlen($channel) < 3) {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>❌ Noto'g'ri format!</b>\n\nKanal usernameini yoki linkini yuboring.\nMasalan: <code>@kanalim</code> yoki <code>https://t.me/kanalim</code>",
            'parse_mode' => "HTML",
            'reply_markup' => mainMenu()
        ]);
        exit;
    }

    $chat = bot('getChat', ['chat_id' => "@$channel"]);
    if (!$chat || !isset($chat->result->id)) {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>❌ Kanal topilmadi!</b>\nUsername yoki linkni to'g'ri yozing.",
            'parse_mode' => "HTML",
            'reply_markup' => mainMenu()
        ]);
        clearUserState($fromid);
        exit;
    }

    // Qaysi guruhga ulash kerakligini so'raymiz yoki oxirgi guruhga
    // Oddiy variant: foydalanuvchiga guruh ID sini so'rash o'rniga, 
    // "Guruhda /setobuna ishlating" deb yo'riqnoma beramiz, lekin state ni tozalaymiz
    // Yaxshiroq: barcha guruhlarga emas, balki foydalanuvchi keyin guruhda ishlatadi.

    clearUserState($fromid);

    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => "<b>✅ Kanal topildi:</b> <b>" . ($chat->result->title ?? $channel) . "</b>\nUsername: @{$channel}\n\n📌 Endi <b>guruhda</b> quyidagi buyruqni yuboring:\n<code>/setobuna @{$channel}</code>\n\nYoki shu yerdan guruhni tanlab o'rnatish uchun guruh ID sini yuboring (ixtiyoriy).",
        'parse_mode' => "HTML",
        'reply_markup' => mainMenu()
    ]);
    exit;
}

// ==================== /start ====================
if ($text == "/start" || $text == "/start@$bot") {
    $is_menu_admin = ($chat_type == 'private' && canUseMenu($fromid));
    $menu_text = $is_menu_admin
        ? "Quyidagi menyudan foydalaning 👇"
        : "Bot guruhlarda ishlaydi. Admin bo'lsangiz guruhga qo'shing.";

    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => "<b>👋 Salom, <a href='tg://user?id=$fromid'>$name</a>!</b>

🤖 Bu bot guruhingizni boshqarishga yordam beradi.

📚 <b>Imkoniyatlar:</b>
<blockquote>🫂 Yangi a'zolarni salomlashadi
🔞 So'kinishni o'chiradi + mute
⚠️ Taqiqlangan so'zlarni bloklaydi
📌 Majburiy obuna
✅ Zayafkalarni avtomatik tasdiqlaydi</blockquote>

$menu_text

♻️ <b>Versiya:</b> v0.0.3",
        'parse_mode' => "HTML",
        'reply_markup' => $is_menu_admin ? mainMenu() : removeKeyboard()
    ]);
    exit;
}

// ==================== REPLY MENU TUGMALARI (faqat admin) ====================
$menu_buttons = ["✅ Zayavka tasdiqlash", "➕ Guruh qo'shish", "📋 Guruhlarim", "📢 Kanal ulash", "⭐ Premium a'zolar", "❌ Bekor qilish"];
if (in_array($raw_text, $menu_buttons, true)) {
    // Guruhda menyu ishlamasin; private da faqat admin
    if ($chat_type != 'private' || !canUseMenu($fromid)) {
        if ($chat_type == 'private') {
            bot('sendMessage', [
                'chat_id' => $cid,
                'text' => "<b>❌ Bu menyu faqat adminlar uchun!</b>",
                'parse_mode' => "HTML",
                'reply_markup' => removeKeyboard()
            ]);
        }
        exit;
    }
}

// --- ✅ Zayavka tasdiqlash ---
if ($raw_text == "✅ Zayavka tasdiqlash") {
    if ($chat_type == 'private') {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>✅ Zayavka (join request) tasdiqlash</b>\n\nBot qo'shilgan barcha guruh va kanallarda <b>zayavkalar avtomatik tasdiqlanadi</b>.\n\nAgar biror guruhda o'chirmoqchi bo'lsangiz, shu guruhda admin sifatida:\n<code>/zayavka off</code>\n\nQayta yoqish:\n<code>/zayavka on</code>",
            'parse_mode' => "HTML",
            'reply_markup' => mainMenu()
        ]);
    } else {
        // Guruhda toggle
        if (!isAdmin($cid, $fromid)) {
            bot('sendMessage', [
                'chat_id' => $cid,
                'text' => "<b>❌ Faqat adminlar o'zgartira oladi!</b>",
                'parse_mode' => "HTML"
            ]);
            exit;
        }
        $settings = getSettings($cid);
        $current = $settings['auto_approve'] ?? true;
        $settings['auto_approve'] = !$current;
        saveSettings($cid, $settings);

        $status = $settings['auto_approve'] ? "✅ YOQILGAN" : "❌ O'CHIRILGAN";
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>Zayavka tasdiqlash:</b> $status",
            'parse_mode' => "HTML"
        ]);
    }
    exit;
}

// --- ➕ Guruh qo'shish ---
if ($raw_text == "➕ Guruh qo'shish") {
    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => "<b>➕ Botni guruhga qo'shish</b>\n\nQuyidagi tugmani bosib botni o'zingizning guruhingizga qo'shing.\nBotni admin qilib qo'ying (xabar o'chirish, cheklash huquqlari bilan).",
        'parse_mode' => "HTML",
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [['text' => "➕ Guruhga qo'shish", 'url' => "https://t.me/$bot?startgroup=new"]]
            ]
        ])
    ]);
    // Menuni qayta yuborish (private uchun)
    if ($chat_type == 'private') {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "Asosiy menyu:",
            'reply_markup' => mainMenu()
        ]);
    }
    exit;
}

// --- 📋 Guruhlarim ---
if ($raw_text == "📋 Guruhlarim") {
    $groups = getGroups();
    if (empty($groups)) {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>📋 Guruhlarim</b>\n\nHozircha bot hech qanday guruhga qo'shilmagan.\n\n➕ Guruh qo'shish tugmasidan foydalaning.",
            'parse_mode' => "HTML",
            'reply_markup' => mainMenu()
        ]);
        exit;
    }

    $text_out = "<b>📋 Bot qo'shilgan guruhlar:</b>\n\n";
    $i = 1;
    foreach ($groups as $gid => $info) {
        $title = $info['title'] ?? 'Noma\'lum';
        $text_out .= "$i. <b>$title</b>\n   ID: <code>$gid</code>\n\n";
        $i++;
        if ($i > 30) {
            $text_out .= "... va yana " . (count($groups) - 30) . " ta guruh";
            break;
        }
    }

    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => $text_out,
        'parse_mode' => "HTML",
        'reply_markup' => mainMenu()
    ]);
    exit;
}

// --- 📢 Kanal ulash ---
if ($raw_text == "📢 Kanal ulash") {
    if ($chat_type == 'private') {
        saveUserState($fromid, ['action' => 'wait_channel']);
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>📢 Majburiy obuna uchun kanal ulash</b>\n\nKanalning usernameini yoki linkini yuboring:\n\nMasalan:\n<code>@mening_kanalim</code>\nyoki\n<code>https://t.me/mening_kanalim</code>\n\nBekor qilish uchun /start bosing.",
            'parse_mode' => "HTML",
            'reply_markup' => json_encode([
                'keyboard' => [[['text' => '❌ Bekor qilish']]],
                'resize_keyboard' => true,
                'one_time_keyboard' => true
            ])
        ]);
    } else {
        // Guruhda to'g'ridan-to'g'ri yo'riqnoma
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>📢 Kanal ulash</b>\n\nVaqtli majburiy obuna:\n<code>/ulash @kanal 1h</code>\n<code>/ulash @kanal 30m</code>\n<code>/ulash @kanal 2d</code>\n\nDoimiy:\n<code>/setobuna @kanal</code>\n\nO'chirish:\n<code>/delobuna</code>",
            'parse_mode' => "HTML"
        ]);
    }
    exit;
}

// Bekor qilish
if ($raw_text == "❌ Bekor qilish") {
    clearUserState($fromid);
    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => "Bekor qilindi.",
        'reply_markup' => mainMenu()
    ]);
    exit;
}

// --- ⭐ Premium a'zolar (faqat soni) ---
if ($raw_text == "⭐ Premium a'zolar") {
    if (in_array($chat_type, ['group', 'supergroup'])) {
        // Avval adminlarni yangilab olamiz, keyin sonini ko'rsatamiz
        $list = refreshPremiumFromAdmins($cid);
        $soni = count($list);
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>⭐ Premium a'zolar:</b> <code>$soni</code> ta",
            'parse_mode' => "HTML"
        ]);
    } else {
        // Private: har bir guruh bo'yicha alohida
        $groups = getGroups();
        if (empty($groups)) {
            bot('sendMessage', [
                'chat_id' => $cid,
                'text' => "<b>⭐ Premium a'zolar:</b> <code>0</code> ta\n\nBot hali hech qanday guruhga qo'shilmagan.",
                'parse_mode' => "HTML",
                'reply_markup' => mainMenu()
            ]);
            exit;
        }

        $text_out = "<b>⭐ Premium a'zolar statistikasi</b>\n\n";
        $total = 0;
        $i = 1;

        foreach ($groups as $gid => $info) {
            // Har bir guruh uchun adminlarni yangilab olamiz
            $list = refreshPremiumFromAdmins($gid);
            $soni = count($list);
            $total += $soni;
            $title = htmlspecialchars($info['title'] ?? 'Noma\'lum guruh');
            $text_out .= "$i. <b>$title</b>\n   ⭐ Premium: <code>$soni</code> ta\n\n";
            $i++;
        }

        $text_out .= "━━━━━━━━━━━━━━\n";
        $text_out .= "<b>Jami:</b> <code>$total</code> ta";

        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => $text_out,
            'parse_mode' => "HTML",
            'reply_markup' => mainMenu()
        ]);
    }
    exit;
}

// ==================== ADMIN BUYRUQLARI ====================

// --- /zayavka on|off ---
if (stripos($text, "/zayavka") === 0) {
    if (!isAdmin($cid, $fromid) && $chat_type != 'private') {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>❌ Faqat adminlar!</b>",
            'parse_mode' => "HTML"
        ]);
        exit;
    }

    $parts = explode(" ", $text);
    $action = isset($parts[1]) ? $parts[1] : '';

    if ($chat_type == 'private') {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "Bu buyruqni guruh ichida ishlating.",
            'reply_markup' => mainMenu()
        ]);
        exit;
    }

    $settings = getSettings($cid);
    if ($action == 'on' || $action == 'yoq') {
        $settings['auto_approve'] = true;
        saveSettings($cid, $settings);
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>✅ Zayavka tasdiqlash YOQILDI</b>",
            'parse_mode' => "HTML"
        ]);
    } elseif ($action == 'off' || $action == 'ochir') {
        $settings['auto_approve'] = false;
        saveSettings($cid, $settings);
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>❌ Zayavka tasdiqlash O'CHIRILDI</b>",
            'parse_mode' => "HTML"
        ]);
    } else {
        $current = ($settings['auto_approve'] ?? true) ? "✅ YOQILGAN" : "❌ O'CHIRILGAN";
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>Zayavka holati:</b> $current\n\n<code>/zayavka on</code> — yoqish\n<code>/zayavka off</code> — o'chirish",
            'parse_mode' => "HTML"
        ]);
    }
    exit;
}

// --- /premium (to'liq ro'yxat - eski buyruq) ---
if (stripos($text, "/premium") === 0) {
    if (!isAdmin($cid, $fromid)) {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>❌ Bu buyruq faqat adminlar uchun!</b>",
            'parse_mode' => "HTML"
        ]);
        exit;
    }

    // Adminlarni ham tekshirib yangilaymiz
    $list = refreshPremiumFromAdmins($cid);
    $count = count($list);

    if ($count == 0) {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>⭐ Premium statistikasi</b>\n\nHozircha guruhda hech qanday premium a'zo aniqlanmadi.\n\n<i>Bot xabar yozgan premium foydalanuvchilarni va adminlarni avtomatik ro'yxatga oladi.</i>",
            'parse_mode' => "HTML"
        ]);
        exit;
    }

    $text_out = "<b>⭐ Premium a'zolar statistikasi</b>\n\n";
    $text_out .= "📊 Jami aniqlangan: <b>$count</b> ta\n\n";
    $text_out .= "<b>Ro'yxat:</b>\n";

    $i = 1;
    foreach ($list as $uid => $info) {
        $uname = !empty($info['username']) ? "@{$info['username']}" : ($info['name'] ?: "Noma'lum");
        $text_out .= "$i. $uname  (<code>$uid</code>)\n";
        $i++;
        if ($i > 40) {
            $text_out .= "\n... va yana " . ($count - 40) . " ta";
            break;
        }
    }

    $text_out .= "\n\n<i>Oxirgi yangilanish: " . date("d.m.Y H:i") . "</i>";

    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => $text_out,
        'parse_mode' => "HTML"
    ]);
    exit;
}

// --- Vaqtni sekundga o'tkazish (1h, 30m, 2d va h.k.) ---
function parseDuration($str) {
    $str = strtolower(trim($str));
    if (preg_match('/^(\d+)\s*(h|hour|hours|soat)$/u', $str, $m)) {
        return intval($m[1]) * 3600;
    }
    if (preg_match('/^(\d+)\s*(m|min|minute|minutes|daqiqa)$/u', $str, $m)) {
        return intval($m[1]) * 60;
    }
    if (preg_match('/^(\d+)\s*(d|day|days|kun)$/u', $str, $m)) {
        return intval($m[1]) * 86400;
    }
    // faqat raqam bo'lsa — soat deb hisoblaymiz
    if (preg_match('/^(\d+)$/', $str, $m)) {
        return intval($m[1]) * 3600;
    }
    return 0;
}

// --- /ulash (vaqtli majburiy obuna) ---
if (stripos($text, "/ulash") === 0) {
    if (!isAdmin($cid, $fromid)) {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>❌ Bu buyruq faqat adminlar uchun!</b>",
            'parse_mode' => "HTML"
        ]);
        exit;
    }

    $parts = preg_split('/\s+/', trim($message->text), 3);
    // /ulash @kanal 1h
    if (count($parts) < 3) {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>📌 Foydalanish:</b>\n<code>/ulash @kanal 1h</code>\n<code>/ulash @kanal 30m</code>\n<code>/ulash @kanal 2d</code>\n\nMisollar:\n• 1h / 1soat — 1 soat\n• 30m / 30daqiqa — 30 daqiqa\n• 2d / 2kun — 2 kun",
            'parse_mode' => "HTML"
        ]);
        exit;
    }

    $channel = $parts[1];
    $duration_str = $parts[2];

    if (strpos($channel, "t.me/") !== false) {
        $channel = preg_replace('#https?://t\.me/#i', '', $channel);
        $channel = explode("?", $channel)[0];
    }
    $channel = ltrim($channel, "@");

    $seconds = parseDuration($duration_str);
    if ($seconds <= 0) {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>❌ Vaqt noto'g'ri!</b>\nMasalan: <code>1h</code>, <code>30m</code>, <code>2d</code>",
            'parse_mode' => "HTML"
        ]);
        exit;
    }

    $chat = bot('getChat', ['chat_id' => "@$channel"]);
    if (!$chat || !isset($chat->result->id)) {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>❌ Kanal topilmadi!</b>\nUsername yoki linkni to'g'ri yozing.",
            'parse_mode' => "HTML"
        ]);
        exit;
    }

    $until = time() + $seconds;
    $settings = getSettings($cid);
    $settings['forced_channel'] = "@$channel";
    $settings['forced_channel_id'] = $chat->result->id;
    $settings['forced_channel_title'] = $chat->result->title ?? $channel;
    $settings['forced_until'] = $until;
    saveSettings($cid, $settings);

    $until_text = date("d.m.Y H:i", $until);
    $human = '';
    if ($seconds >= 86400) $human = round($seconds / 86400) . " kun";
    elseif ($seconds >= 3600) $human = round($seconds / 3600) . " soat";
    else $human = round($seconds / 60) . " daqiqa";

    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => "<b>✅ Majburiy obuna ulandi!</b>\n\nKanal: <b>{$settings['forced_channel_title']}</b>\nUsername: @{$channel}\n⏱ Muddat: <b>$human</b>\n🕐 Tugash vaqti: <code>$until_text</code>\n\nVaqt tugagach avtomatik o'chadi.",
        'parse_mode' => "HTML"
    ]);
    exit;
}

// --- /setobuna (doimiy) ---
if (stripos($text, "/setobuna") === 0) {
    if (!isAdmin($cid, $fromid)) {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>❌ Bu buyruq faqat adminlar uchun!</b>",
            'parse_mode' => "HTML"
        ]);
        exit;
    }

    $parts = explode(" ", $message->text, 2);
    if (!isset($parts[1]) || empty(trim($parts[1]))) {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>📌 Foydalanish:</b>\n<code>/setobuna @kanalusername</code> — doimiy\n<code>/ulash @kanal 1h</code> — vaqtli",
            'parse_mode' => "HTML"
        ]);
        exit;
    }

    $channel = trim($parts[1]);
    if (strpos($channel, "t.me/") !== false) {
        $channel = preg_replace('#https?://t\.me/#i', '', $channel);
        $channel = explode("?", $channel)[0];
    }
    $channel = ltrim($channel, "@");

    $chat = bot('getChat', ['chat_id' => "@$channel"]);
    if (!$chat || !isset($chat->result->id)) {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>❌ Kanal topilmadi!</b>\nUsername yoki linkni to'g'ri yozing.",
            'parse_mode' => "HTML"
        ]);
        exit;
    }

    $settings = getSettings($cid);
    $settings['forced_channel'] = "@$channel";
    $settings['forced_channel_id'] = $chat->result->id;
    $settings['forced_channel_title'] = $chat->result->title ?? $channel;
    unset($settings['forced_until']); // doimiy
    saveSettings($cid, $settings);

    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => "<b>✅ Majburiy obuna o'rnatildi (doimiy)!</b>\n\nKanal: <b>{$settings['forced_channel_title']}</b>\nUsername: @{$channel}\n\nEndi oddiy foydalanuvchilar yozishdan oldin shu kanalga obuna bo'lishi shart.\n\nVaqtli qilish uchun: <code>/ulash @kanal 1h</code>",
        'parse_mode' => "HTML"
    ]);
    exit;
}

// --- /delobuna ---
if (stripos($text, "/delobuna") === 0) {
    if (!isAdmin($cid, $fromid)) {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>❌ Bu buyruq faqat adminlar uchun!</b>",
            'parse_mode' => "HTML"
        ]);
        exit;
    }

    $settings = getSettings($cid);
    unset($settings['forced_channel'], $settings['forced_channel_id'], $settings['forced_channel_title'], $settings['forced_until']);
    saveSettings($cid, $settings);

    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => "<b>✅ Majburiy obuna o'chirildi!</b>",
        'parse_mode' => "HTML"
    ]);
    exit;
}

// --- /obuna (hozirgi holat) ---
if (stripos($text, "/obuna") === 0) {
    if (!isAdmin($cid, $fromid)) {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>❌ Bu buyruq faqat adminlar uchun!</b>",
            'parse_mode' => "HTML"
        ]);
        exit;
    }

    $settings = getSettings($cid);
    if (empty($settings['forced_channel'])) {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>ℹ️ Hozircha majburiy obuna o'rnatilmagan.</b>\n\nVaqtli: <code>/ulash @kanal 1h</code>\nDoimiy: <code>/setobuna @kanal</code>",
            'parse_mode' => "HTML"
        ]);
    } else {
        $extra = "";
        if (!empty($settings['forced_until'])) {
            if (time() >= $settings['forced_until']) {
                $extra = "\n⏱ <b>Muddat tugagan</b> (avtomatik o'chadi)";
            } else {
                $left = $settings['forced_until'] - time();
                $until_text = date("d.m.Y H:i", $settings['forced_until']);
                if ($left >= 3600) $left_h = round($left / 3600) . " soat";
                else $left_h = round($left / 60) . " daqiqa";
                $extra = "\n⏱ Tugash: <code>$until_text</code> (qoldi: $left_h)";
            }
        } else {
            $extra = "\n♾ <b>Doimiy</b>";
        }
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>📌 Hozirgi majburiy obuna:</b>\n\nKanal: <b>{$settings['forced_channel_title']}</b>\nUsername: {$settings['forced_channel']}$extra",
            'parse_mode' => "HTML"
        ]);
    }
    exit;
}

// ==================== MAJBURIY OBUNA TEKSHIRUVI ====================
$settings = getSettings($cid);

// Muddat tugagan bo'lsa avtomatik o'chiramiz
if (!empty($settings['forced_channel']) && !empty($settings['forced_until']) && time() >= $settings['forced_until']) {
    unset($settings['forced_channel'], $settings['forced_channel_id'], $settings['forced_channel_title'], $settings['forced_until']);
    saveSettings($cid, $settings);
    $settings = getSettings($cid); // yangilangan
}

if (!empty($settings['forced_channel']) && !isAdmin($cid, $fromid) && $fromid != $bot_id && in_array($chat_type, ['group', 'supergroup'])) {
    $member = bot('getChatMember', [
        'chat_id' => $settings['forced_channel'],
        'user_id' => $fromid
    ]);

    $status = $member->result->status ?? 'left';
    if (!in_array($status, ['member', 'administrator', 'creator'])) {
        // Xabarni o'chirmaymiz, reply qilib majburiy obunani chiqaramiz
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>⚠️ Avval kanalga obuna bo'ling!</b>\n\nGuruhda yozish uchun quyidagi kanalga obuna bo'lishingiz shart:",
            'parse_mode' => "HTML",
            'reply_to_message_id' => $mid,
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => "✅ Kanalga obuna bo'lish", 'url' => "https://t.me/" . ltrim($settings['forced_channel'], "@")]],
                    [['text' => "🔄 Tekshirish", 'callback_data' => "check_sub"]]
                ]
            ])
        ]);
        exit;
    }
}

// ==================== TASHQI LINK BLOKLASH ====================
if (preg_match('/https?:\/\/(?!t\.me)|www\./i', $text) && in_array($chat_type, ['group', 'supergroup'])) {
    if (!isAdmin($cid, $fromid)) {
        bot('deleteMessage', [
            'chat_id' => $cid,
            'message_id' => $mid
        ]);
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "🔗 <b>Tashqi havola yuborish taqiqlangan!</b>\n<i>❌ Xabar o‘chirib yuborildi!</i>",
            'parse_mode' => "HTML"
        ]);
    }
    exit;
}

// ==================== /mute ====================
if (stripos($text, "/mute") === 0 && isset($message->reply_to_message)) {
    if (!isAdmin($cid, $fromid)) {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>❌ Sizda ushbu buyruqni ishlatish huquqi yo‘q!</b>",
            'parse_mode' => "HTML"
        ]);
        exit;
    }

    $reply_user_id = $message->reply_to_message->from->id;
    if (isAdmin($cid, $reply_user_id)) {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>❌ Admin foydalanuvchiga mute berib bo‘lmaydi!</b>",
            'parse_mode' => "HTML"
        ]);
        exit;
    }

    $parts = explode(" ", $text);
    $amount = isset($parts[1]) ? intval($parts[1]) : 1;
    $unit = isset($parts[2]) ? strtolower($parts[2]) : "minute";

    switch ($unit) {
        case "minute":
        case "minutes":
        case "minut":
        case "daqiqa":
            $seconds = $amount * 60;
            break;
        case "hour":
        case "hours":
        case "soat":
            $seconds = $amount * 3600;
            break;
        case "day":
        case "days":
        case "kun":
            $seconds = $amount * 86400;
            break;
        default:
            $seconds = $amount * 60;
    }

    bot('restrictChatMember', [
        'chat_id' => $cid,
        'user_id' => $reply_user_id,
        'permissions' => json_encode([
            'can_send_messages' => false,
            'can_send_media_messages' => false,
            'can_send_polls' => false,
            'can_send_other_messages' => false,
            'can_add_web_page_previews' => false
        ]),
        'until_date' => time() + $seconds
    ]);

    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => "<b>⏱ Foydalanuvchi</b> {$message->reply_to_message->from->first_name} <u>{$amount} {$unit}</u> <b>vaqtga mute qilindi ✅</b>",
        'parse_mode' => "HTML"
    ]);
    exit;
}

// ==================== /unmute ====================
if (stripos($text, "/unmute") === 0 && isset($message->reply_to_message)) {
    if (!isAdmin($cid, $fromid)) {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>❌ Sizda ushbu buyruqni ishlatish huquqi yo‘q!</b>",
            'parse_mode' => "HTML"
        ]);
        exit;
    }

    $reply_user_id = $message->reply_to_message->from->id;
    if (isAdmin($cid, $reply_user_id)) {
        bot('sendMessage', [
            'chat_id' => $cid,
            'text' => "<b>❌ Admin foydalanuvchiga mute olib bo‘lmaydi!</b>",
            'parse_mode' => "HTML"
        ]);
        exit;
    }

    bot('restrictChatMember', [
        'chat_id' => $cid,
        'user_id' => $reply_user_id,
        'permissions' => json_encode([
            'can_send_messages' => true,
            'can_send_media_messages' => true,
            'can_send_polls' => true,
            'can_send_other_messages' => true,
            'can_add_web_page_previews' => true
        ])
    ]);

    bot('sendMessage', [
        'chat_id' => $cid,
        'text' => "<b>✅ Foydalanuvchi</b> {$message->reply_to_message->from->first_name} <b>mute holatidan chiqarildi!</b>",
        'parse_mode' => "HTML"
    ]);
    exit;
}

// ==================== SO'KINISH TEKSHIRUVI ====================
if (in_array($chat_type, ['group', 'supergroup'])) {
    $pattern = '/\b(' . implode('|', array_map('preg_quote', $sokinish_royhati)) . ')\b/ui';
    if (preg_match($pattern, $text)) {
        if (!isAdmin($cid, $fromid)) {
            bot('deleteMessage', [
                'chat_id' => $cid,
                'message_id' => $mid
            ]);
            bot('restrictChatMember', [
                'chat_id' => $cid,
                'user_id' => $fromid,
                'permissions' => json_encode([
                    'can_send_messages' => false,
                    'can_send_media_messages' => false,
                    'can_send_polls' => false,
                    'can_send_other_messages' => false,
                    'can_add_web_page_previews' => false
                ]),
                'until_date' => time() + 60
            ]);
            bot('sendMessage', [
                'chat_id' => $cid,
                'text' => "<b>🚫 Foydalanuvchi</b> [$fromid] <b>so‘kingani uchun 1 daqiqaga mute qilindi!</b>",
                'parse_mode' => "HTML"
            ]);
        }
        exit;
    }

    // ==================== TAQIQLANGAN SO'ZLAR ====================
    $taqiq = '/\b(' . implode('|', array_map('preg_quote', $taqiqlangan_royhati)) . ')\b/ui';
    if (preg_match($taqiq, $text)) {
        if (!isAdmin($cid, $fromid)) {
            bot('deleteMessage', [
                'chat_id' => $cid,
                'message_id' => $mid
            ]);
            bot('restrictChatMember', [
                'chat_id' => $cid,
                'user_id' => $fromid,
                'permissions' => json_encode([
                    'can_send_messages' => false,
                    'can_send_media_messages' => false,
                    'can_send_polls' => false,
                    'can_send_other_messages' => false,
                    'can_add_web_page_previews' => false
                ]),
                'until_date' => time() + 60
            ]);
            bot('sendMessage', [
                'chat_id' => $cid,
                'text' => "<b>⛔ Foydalanuvchi</b> [$fromid] <b>taqiqlangan so‘z ishlatdi! 1 daqiqaga mute qilindi!</b>",
                'parse_mode' => "HTML"
            ]);
        }
        exit;
    }
}
?>
