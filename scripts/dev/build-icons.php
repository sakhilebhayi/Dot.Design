<?php

// Dev tool: regenerates resources/design/icons.json (own-drawn, 24x24 grid, stroke style).
// Paths use ONLY the commands M L H V C S Q T Z. Circles and rounded corners are cubic Beziers.
// Usage: php scripts/dev/build-icons.php
declare(strict_types=1);

const K = 0.5523;

function f(float $n): string
{
    $s = rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');

    return $s === '-0' || $s === '' ? '0' : $s;
}

function e(float $cx, float $cy, float $rx, float $ry): string
{
    $kx = K * $rx;
    $ky = K * $ry;

    return 'M'.f($cx + $rx).' '.f($cy).'C'.f($cx + $rx).' '.f($cy + $ky).' '.f($cx + $kx).' '.f($cy + $ry).' '.f($cx).' '.f($cy + $ry)
        .'C'.f($cx - $kx).' '.f($cy + $ry).' '.f($cx - $rx).' '.f($cy + $ky).' '.f($cx - $rx).' '.f($cy)
        .'C'.f($cx - $rx).' '.f($cy - $ky).' '.f($cx - $kx).' '.f($cy - $ry).' '.f($cx).' '.f($cy - $ry)
        .'C'.f($cx + $kx).' '.f($cy - $ry).' '.f($cx + $rx).' '.f($cy - $ky).' '.f($cx + $rx).' '.f($cy).'Z';
}

function c(float $cx, float $cy, float $r): string
{
    return e($cx, $cy, $r, $r);
}

function rr(float $x, float $y, float $w, float $h, float $r): string
{
    $k = K * $r;

    return 'M'.f($x + $r).' '.f($y).'H'.f($x + $w - $r)
        .'C'.f($x + $w - $r + $k).' '.f($y).' '.f($x + $w).' '.f($y + $r - $k).' '.f($x + $w).' '.f($y + $r)
        .'V'.f($y + $h - $r)
        .'C'.f($x + $w).' '.f($y + $h - $r + $k).' '.f($x + $w - $r + $k).' '.f($y + $h).' '.f($x + $w - $r).' '.f($y + $h)
        .'H'.f($x + $r)
        .'C'.f($x + $r - $k).' '.f($y + $h).' '.f($x).' '.f($y + $h - $r + $k).' '.f($x).' '.f($y + $h - $r)
        .'V'.f($y + $r)
        .'C'.f($x).' '.f($y + $r - $k).' '.f($x + $r - $k).' '.f($y).' '.f($x + $r).' '.f($y).'Z';
}

/** Regular star with $n points. */
function starPath(float $cx, float $cy, float $ro, float $ri, int $n): string
{
    $d = '';
    for ($i = 0; $i < $n * 2; $i++) {
        $a = M_PI * $i / $n - M_PI / 2;
        $r = $i % 2 ? $ri : $ro;
        $d .= ($i === 0 ? 'M' : 'L').f($cx + cos($a) * $r).' '.f($cy + sin($a) * $r);
    }

    return $d.'Z';
}

/** A dot: a zero-length stroke that renders as a round dot with round caps. */
function dot(float $x, float $y): string
{
    return 'M'.f($x).' '.f($y).'H'.f($x + 0.01);
}

$I = [];
function ic(string $key, string $cat, string $tags, string $d): void
{
    global $I;
    $I[$key] = ['category' => $cat, 'tags' => array_map('trim', explode(',', $tags)), 'd' => $d];
}

$check = 'M8 12.5L11 15.5L16.5 9';

// ---- business -------------------------------------------------------------------------------
ic('briefcase', 'business', 'work,job,office,bag', rr(3, 7, 18, 13, 2).'M9 7V5C9 4.4 9.4 4 10 4H14C14.6 4 15 4.4 15 5V7M3 13H21');
ic('building', 'business', 'office,company,city,tower', rr(5, 3, 14, 18, 1).'M9 8H11M13 8H15M9 12H11M13 12H15M10 21V17H14V21');
ic('store', 'business', 'shop,retail,awning,market', 'M4 4H20L21 9H3ZM3 9C3 10.5 4.2 11.5 5.5 11.5C6.8 11.5 8 10.5 8 9M8 9C8 10.5 9.2 11.5 10.5 11.5C11.8 11.5 13 10.5 13 9M13 9C13 10.5 14.2 11.5 15.5 11.5C16.8 11.5 18 10.5 18 9M18 9C18 10.5 19.2 11.5 20.5 11.5M5 11.5V20H19V11.5M10 20V14H14V20');
ic('chart-bar', 'business', 'stats,graph,growth,report', 'M3 20H21'.rr(5, 12, 3, 8, 0.5).rr(10.5, 6, 3, 14, 0.5).rr(16, 9, 3, 11, 0.5));
ic('chart-line', 'business', 'stats,trend,growth,analytics', 'M3 3V21H21M7 15L11 11L14 14L20 7M16 7H20V11');
ic('pie-chart', 'business', 'stats,share,report,segments', c(12, 12, 9).'M12 3V12H21M12 12L5.6 18.4');
ic('target', 'business', 'goal,aim,focus,bullseye', c(12, 12, 9).c(12, 12, 5).c(12, 12, 1));
ic('lightbulb', 'business', 'idea,tip,innovation,light', 'M9 18H15M10 21H14M12 3C8.1 3 5.5 5.9 5.5 9.2C5.5 11.5 6.8 13.2 8.2 14.6C8.8 15.2 9 15.8 9 16.5V18H15V16.5C15 15.8 15.2 15.2 15.8 14.6C17.2 13.2 18.5 11.5 18.5 9.2C18.5 5.9 15.9 3 12 3Z');
ic('rocket', 'business', 'launch,startup,fast,growth', 'M12 3C16 5 18 9 18 13L15 16H9L6 13C6 9 8 5 12 3Z'.c(12, 10, 1.8).'M6 13L3.5 15.5L6.5 16M18 13L20.5 15.5L17.5 16M10 19L12 21.5L14 19');
ic('trophy', 'business', 'win,award,prize,champion', 'M8 4H16V9C16 11.2 14.2 13 12 13C9.8 13 8 11.2 8 9ZM8 5.5H4.5C4.5 8.5 5.5 10 8 10.3M16 5.5H19.5C19.5 8.5 18.5 10 16 10.3M12 13V17M9 17H15M8 20H16');
ic('award', 'business', 'medal,badge,quality,prize', c(12, 9, 5.5).'M8.5 13.5L7 21L12 18.5L17 21L15.5 13.5');
ic('users', 'business', 'team,people,group,family,staff', c(9, 8, 3.5).'M2.5 20C2.5 16.5 5.5 14 9 14C12.5 14 15.5 16.5 15.5 20M16 4.7C17.7 5 19 6.4 19 8C19 9.6 17.7 11 16 11.3M17.5 14.2C20 14.8 21.5 17 21.5 20');
ic('user', 'business', 'person,profile,account,customer', c(12, 8, 4).'M4 21C4 16.5 7.5 14 12 14C16.5 14 20 16.5 20 21');
ic('id-card', 'business', 'badge,staff,identity,pass', rr(3, 5, 18, 14, 2).c(9, 11, 2.2).'M5.5 16C5.8 14.5 7.2 13.7 9 13.7C10.8 13.7 12.2 14.5 12.5 16M14.5 10H18.5M14.5 14H18.5');
ic('clipboard', 'business', 'list,tasks,notes,form', rr(5, 4, 14, 17, 2).rr(9, 2.5, 6, 3.5, 1).'M9 11H15M9 15H15');
ic('document', 'business', 'file,page,paper,report', 'M6 3H14L19 8V21H6ZM14 3V8H19M9 13H16M9 17H16');
ic('folder', 'business', 'files,directory,project,archive', 'M3 6C3 5.4 3.4 5 4 5H9L11 7.5H20C20.6 7.5 21 7.9 21 8.5V18C21 18.6 20.6 19 20 19H4C3.4 19 3 18.6 3 18Z');
ic('calculator', 'business', 'maths,accounts,finance,quote', rr(5, 3, 14, 18, 2).rr(8, 6, 8, 3.5, 0.5).dot(9, 13).dot(12, 13).dot(15, 13).dot(9, 16.5).dot(12, 16.5).dot(15, 16.5));
ic('bank', 'business', 'finance,money,institution,columns', 'M3 9L12 3L21 9ZM6 11V17M10 11V17M14 11V17M18 11V17M3 20H21');
ic('key', 'business', 'access,security,lock,entry', c(8, 15, 4.5).'M11.2 11.8L20 3M17 6L19 8M14.5 8.5L16 10');
ic('shield', 'business', 'security,protect,safe,trust', 'M12 3L20 6V12C20 16.5 16.5 19.7 12 21C7.5 19.7 4 16.5 4 12V6Z');
ic('lock', 'business', 'security,private,safe,password', rr(5, 10, 14, 11, 2).'M8 10V7C8 4.8 9.8 3 12 3C14.2 3 16 4.8 16 7V10M12 14.5V16.5');
ic('gear', 'business', 'settings,cog,tools,options', 'M10 3H14L14.6 5.5L16.8 6.7L19.2 5.8L21.2 9.2L19.3 11V13L21.2 14.8L19.2 18.2L16.8 17.3L14.6 18.5L14 21H10L9.4 18.5L7.2 17.3L4.8 18.2L2.8 14.8L4.7 13V11L2.8 9.2L4.8 5.8L7.2 6.7L9.4 5.5Z'.c(12, 12, 3));
ic('flag', 'business', 'goal,milestone,report,country', 'M5 21V4M5 5H19L16 9L19 13H5');
ic('bookmark', 'business', 'save,saved,mark,favourite', 'M6 3H18V21L12 16.5L6 21Z');
ic('layers', 'business', 'stack,design,levels,pages', 'M12 3L21 8L12 13L3 8ZM3 12L12 17L21 12M3 16L12 21L21 16');
ic('presentation', 'business', 'slides,pitch,board,meeting', rr(3, 4, 18, 12, 1.5).'M12 16V20M8 20H16M7 12L10 9L13 11L17 7');

// ---- contact --------------------------------------------------------------------------------
ic('phone', 'contact', 'call,telephone,mobile,contact', 'M5 4H9L10.5 8.5L8.2 10C9.2 12.2 11.8 14.8 14 15.8L15.5 13.5L20 15V19C20 19.6 19.6 20 19 20C11 20 4 13 4 5C4 4.4 4.4 4 5 4Z');
ic('phone-call', 'contact', 'call,ring,telephone,speak', 'M5 4H9L10.5 8.5L8.2 10C9.2 12.2 11.8 14.8 14 15.8L15.5 13.5L20 15V19C20 19.6 19.6 20 19 20C11 20 4 13 4 5C4 4.4 4.4 4 5 4ZM14.5 4.5C17.5 5 19 6.5 19.5 9.5M14.5 8C16 8.3 16.7 9 17 10.5');
ic('mail', 'contact', 'email,envelope,message,inbox', rr(3, 5, 18, 14, 2).'M3 7L12 13.5L21 7');
ic('chat-bubble', 'contact', 'message,talk,comment,support', 'M4 5H20V16H11L6 20V16H4Z');
ic('chat-whatsapp', 'contact', 'whatsapp,message,chat,phone', 'M3.5 20.5L5 16C3.8 14.6 3 12.8 3 11C3 6.6 7 3 12 3C17 3 21 6.6 21 11C21 15.4 17 19 12 19C10.6 19 9.2 18.7 8 18.1ZM9 8.5C9 11.5 12.5 15 15.5 15L16.3 13.3L14.2 12.3L13.2 13C12 12.5 11.5 12 11 10.8L11.7 9.8L10.7 7.7Z');
ic('pin', 'contact', 'location,map,place,address', 'M12 21C12 21 5 14.5 5 9.5C5 5.9 8.1 3 12 3C15.9 3 19 5.9 19 9.5C19 14.5 12 21 12 21Z'.c(12, 9.5, 2.5));
ic('globe', 'contact', 'website,web,world,internet', c(12, 12, 9).e(12, 12, 3.8, 9).'M3 12H21M4.5 7.5C9 9 15 9 19.5 7.5M4.5 16.5C9 15 15 15 19.5 16.5');
ic('clock', 'contact', 'time,hours,opening,schedule', c(12, 12, 9).'M12 7V12L15.5 14');
ic('calendar', 'contact', 'date,event,schedule,booking', rr(3, 5, 18, 16, 2).'M3 10H21M8 3V7M16 3V7');
ic('calendar-check', 'contact', 'booked,date,confirmed,appointment', rr(3, 5, 18, 16, 2).'M3 10H21M8 3V7M16 3V7M9 15L11 17L15 13');
ic('at-sign', 'contact', 'email,handle,mention,address', c(12, 12, 4).'M16 8V13C16 15 17.5 16 19 15.2C20.5 14.4 21 12.8 21 12C21 7 17 3 12 3C7 3 3 7 3 12C3 17 7 21 12 21C14 21 15.5 20.5 17 19.5');
ic('link', 'contact', 'url,chain,connect,share', 'M10 14L14 10M8 11L6.5 12.5C4.8 14.2 4.8 16.8 6.5 18.5C8.2 20.2 10.8 20.2 12.5 18.5L14 17M16 13L17.5 11.5C19.2 9.8 19.2 7.2 17.5 5.5C15.8 3.8 13.2 3.8 11.5 5.5L10 7');
ic('qr-code', 'contact', 'scan,code,pay,link', rr(3, 3, 7, 7, 1).rr(14, 3, 7, 7, 1).rr(3, 14, 7, 7, 1).'M14 14H17V17H14ZM20 14V14.01M20 20V17M17 20H14');
ic('send', 'contact', 'message,submit,paper-plane,share', 'M21 3L10 14M21 3L14 21L10 14L3 10Z');
ic('headset', 'contact', 'support,help,call-centre,service', 'M4 14V12C4 7.6 7.6 4 12 4C16.4 4 20 7.6 20 12V14'.rr(3, 13, 4, 6, 2).rr(17, 13, 4, 6, 2).'M20 19C20 20.5 18 21 14 21H12');
ic('location-arrow', 'contact', 'navigate,gps,directions,map', 'M21 3L3 10.5L10.5 13.5L13.5 21Z');

// ---- commerce -------------------------------------------------------------------------------
ic('cart', 'commerce', 'shopping,basket,buy,store', 'M3 4H6L8 15H18L20 7H7'.c(9.5, 19, 1.5).c(17, 19, 1.5));
ic('bag', 'commerce', 'shopping,purchase,retail,buy', 'M5 8H19L20 21H4ZM9 8V6.5C9 4.6 10.3 3 12 3C13.7 3 15 4.6 15 6.5V8');
ic('tag', 'commerce', 'price,label,sale,offer', 'M3 12.5V4H12.5L21 12.5L12.5 21Z'.c(8, 8, 1.4));
ic('percent', 'commerce', 'discount,sale,offer,deal', 'M19 5L5 19'.c(7.5, 7.5, 2.5).c(16.5, 16.5, 2.5));
ic('gift', 'commerce', 'present,reward,voucher,birthday,offer', rr(3, 9, 18, 4, 1).'M5 13V21H19V13M12 9V21M12 9C12 9 9.5 9 8.5 7.7C7.5 6.3 8.5 4.5 10 5C11.5 5.5 12 9 12 9M12 9C12 9 14.5 9 15.5 7.7C16.5 6.3 15.5 4.5 14 5C12.5 5.5 12 9 12 9');
ic('truck', 'commerce', 'delivery,shipping,transport,courier', 'M2 6H14V17H2ZM14 9.5H18L21 13V17H14'.c(6.5, 18, 2).c(17.5, 18, 2));
ic('credit-card', 'commerce', 'payment,card,pay,bank', rr(2.5, 5, 19, 14, 2).'M2.5 10H21.5M6 15H10');
ic('receipt', 'commerce', 'invoice,bill,slip,payment', 'M5 3H19V21L16.5 19L14 21L12 19L10 21L7.5 19L5 21ZM9 8H15M9 12H15M9 15.5H12');
ic('wallet', 'commerce', 'money,pay,purse,finance', rr(3, 6, 18, 14, 2).'M5 6L16 3V6M16 12.5H21V16.5H16C14.9 16.5 14 15.6 14 14.5C14 13.4 14.9 12.5 16 12.5Z');
ic('coin', 'commerce', 'money,dollar,price,cash', c(12, 12, 9).'M14.5 9C14.5 8 13.4 7.3 12 7.3C10.5 7.3 9.5 8.1 9.5 9.2C9.5 10.4 10.6 10.9 12 11.2C13.5 11.5 14.7 12 14.7 13.2C14.7 14.3 13.6 15 12 15C10.5 15 9.3 14.2 9.3 13M12 6V7.3M12 15V16.5');
ic('basket', 'commerce', 'shopping,groceries,market,buy', 'M3 10H21L19 20H5ZM8 10L11 4M16 10L13 4');
ic('barcode', 'commerce', 'product,scan,stock,code', 'M4 5V19M7 5V19M10 5V19M13.5 5V19M16 5V19M20 5V19');
ic('package', 'commerce', 'box,delivery,parcel,product', 'M12 3L20.5 7.5V16.5L12 21L3.5 16.5V7.5ZM3.5 7.5L12 12L20.5 7.5M12 12V21');
ic('cash', 'commerce', 'money,notes,payment,rand', rr(2.5, 6, 19, 12, 1.5).c(12, 12, 2.8).dot(6, 9).dot(18, 15));
ic('ticket', 'commerce', 'voucher,coupon,event,entry', 'M3 6H21V10C19.3 10 18 10.9 18 12C18 13.1 19.3 14 21 14V18H3V14C4.7 14 6 13.1 6 12C6 10.9 4.7 10 3 10ZM14 7V9M14 11V13M14 15V17');

// ---- food -----------------------------------------------------------------------------------
ic('coffee-cup', 'food', 'coffee,tea,cafe,drink', 'M4 8H16V15C16 18.3 13.3 21 10 21C6.7 21 4 18.3 4 15ZM16 10H18C19.7 10 21 11.3 21 13C21 14.7 19.7 16 18 16H16M8 3C7 4.5 9 5.5 8 7M12 3C11 4.5 13 5.5 12 7');
ic('fork-knife', 'food', 'restaurant,menu,eat,dining', 'M5 3V8C5 9.7 6.3 11 8 11C9.7 11 11 9.7 11 8V3M8 3V21M17 21V3C14.5 4.5 14 8 14 11H17');
ic('pizza', 'food', 'slice,italian,takeaway,fast-food', 'M3 6C8 3 16 3 21 6L12 21Z'.c(9, 9.5, 1.2).c(14.5, 8.5, 1.2).c(12, 13.8, 1.2));
ic('burger', 'food', 'fast-food,takeaway,meal,grill', 'M3.5 11C3.5 7 7 4 12 4C17 4 20.5 7 20.5 11ZM3 14C5 15.5 6.5 12.5 8.5 14C10.5 15.5 12 12.5 14 14C16 15.5 17.5 12.5 19.5 14L21 14M4 17H20V18C20 19.7 18.7 21 17 21H7C5.3 21 4 19.7 4 18Z');
ic('leaf', 'food', 'organic,natural,eco,vegan', 'M5 19C5 11 10 5 20 4C20 14 15 19 7 19ZM5 19L13 11');
ic('flame', 'food', 'hot,spicy,fire,grill', 'M12 21C8.1 21 5.5 18.3 5.5 14.5C5.5 11 8 9 9 6.5C9.7 7.5 10.2 8.2 10.8 8.5C11.6 7 12 5 12 3C15 5 18.5 9.5 18.5 14.5C18.5 18.3 15.9 21 12 21ZM12 21C10.3 21 9 19.7 9 18C9 16.2 10.5 15.2 12 13C13.5 15.2 15 16.2 15 18C15 19.7 13.7 21 12 21Z');
ic('wine-glass', 'food', 'drink,bar,restaurant,celebrate', 'M7 3H17L16.5 8C16.2 10.7 14.3 12.5 12 12.5C9.7 12.5 7.8 10.7 7.5 8ZM12 12.5V20M8 21H16M7.3 7H16.7');
ic('cake', 'food', 'birthday,dessert,bakery,celebrate', 'M4 20H20M5 20V13C5 12.4 5.4 12 6 12H18C18.6 12 19 12.4 19 13V20M5 16C7 17.5 9 17.5 11 16C13 14.5 15 14.5 17 16L19 16M12 12V8M12 6.5C11 5.5 11.3 4 12 3.5C12.7 4 13 5.5 12 6.5Z');
ic('ice-cream', 'food', 'dessert,sweet,cone,summer', 'M7.5 11L12 21L16.5 11M6.5 11C5.5 7.5 8 3.5 12 3.5C16 3.5 18.5 7.5 17.5 11Z');
ic('bowl', 'food', 'soup,meal,hot,restaurant', 'M3 11H21C21 16 17 20 12 20C7 20 3 16 3 11ZM8 4C7 5.5 9 6.5 8 8M12 4C11 5.5 13 6.5 12 8M16 4C15 5.5 17 6.5 16 8');
ic('bottle', 'food', 'drink,water,beverage,milk', 'M10 3H14V7C14 9 16 9.5 16 12V20C16 20.6 15.6 21 15 21H9C8.4 21 8 20.6 8 20V12C8 9.5 10 9 10 7ZM8 14H16');
ic('apple', 'food', 'fruit,fresh,healthy,grocery', 'M12 7C10 5.5 5 6 5 12C5 16.5 8 21 10.5 21C11.3 21 11.6 20.6 12 20.6C12.4 20.6 12.7 21 13.5 21C16 21 19 16.5 19 12C19 6 14 5.5 12 7ZM12 7C12 5 13 3.5 15 3');
ic('cupcake', 'food', 'bakery,sweet,dessert,treat', 'M5 11L7 20H17L19 11M4 11H20C20 7.5 17 6 15 6.2C14.5 4.2 13 3 12 3C11 3 9.5 4.2 9 6.2C7 6 4 7.5 4 11Z');
ic('cocktail', 'food', 'drink,bar,party,happy-hour', 'M4 4H20L12 13ZM12 13V20M8 20H16');
ic('beer', 'food', 'drink,pub,bar,lager', 'M5 6H15V19C15 20.1 14.1 21 13 21H7C5.9 21 5 20.1 5 19ZM15 9H17.5C18.9 9 20 10.1 20 11.5V14C20 15.4 18.9 16.5 17.5 16.5H15M5 6C4.5 4 7 3 8.5 4C9.5 2.5 12 2.5 13 4C14.5 3.5 16 4.5 15 6');

// ---- social ---------------------------------------------------------------------------------
ic('heart', 'social', 'like,love,wedding,favourite,care', 'M12 20.5C5 15 3 11.5 3 8.5C3 5.8 5 4 7.5 4C9.5 4 11 5 12 6.8C13 5 14.5 4 16.5 4C19 4 21 5.8 21 8.5C21 11.5 19 15 12 20.5Z');
ic('thumbs-up', 'social', 'like,approve,good,review', 'M7 11L11 3C12.7 3 13.5 4.2 13.5 5.5V9H18.5C19.6 9 20.4 10 20.2 11L19 18C18.8 19.2 17.8 20 16.6 20H7ZM3.5 11H7V20H3.5Z');
ic('share', 'social', 'send,network,post,spread', c(6, 12, 2.5).c(18, 6, 2.5).c(18, 18, 2.5).'M8.2 10.8L15.8 7.2M8.2 13.2L15.8 16.8');
ic('camera', 'social', 'photo,picture,snap,shoot', 'M4 7H8L9.5 4.5H14.5L16 7H20C20.6 7 21 7.4 21 8V18C21 18.6 20.6 19 20 19H4C3.4 19 3 18.6 3 18V8C3 7.4 3.4 7 4 7Z'.c(12, 12.5, 3.7));
ic('play', 'social', 'video,start,media,watch', c(12, 12, 9).'M10 8.5L16 12L10 15.5Z');
ic('megaphone', 'social', 'announce,promote,campaign,marketing', 'M3 10V14H7L16 19V5L7 10ZM7 14L8.5 20H11L10 16.5M19 9C20.3 10.3 20.3 13.7 19 15');
ic('message-circle', 'social', 'comment,chat,reply,talk', 'M20.5 11.5C20.5 15.6 16.7 19 12 19C10.9 19 9.8 18.8 8.8 18.4L4 20L5.4 16.3C4.2 14.9 3.5 13.2 3.5 11.5C3.5 7.4 7.3 4 12 4C16.7 4 20.5 7.4 20.5 11.5Z');
ic('bell', 'social', 'notification,alert,reminder,ring', 'M6 16V10.5C6 7.5 8.7 5 12 5C15.3 5 18 7.5 18 10.5V16L20 18H4ZM12 3V5M10 20.5C10.4 21.5 13.6 21.5 14 20.5');
ic('eye', 'social', 'view,see,visible,watch', 'M2 12C4.5 7.5 8 5.5 12 5.5C16 5.5 19.5 7.5 22 12C19.5 16.5 16 18.5 12 18.5C8 18.5 4.5 16.5 2 12Z'.c(12, 12, 3));
ic('video', 'social', 'film,record,clip,movie', rr(3, 6, 13, 12, 2).'M16 10.5L21 7.5V16.5L16 13.5');
ic('music-note', 'social', 'audio,song,sound,playlist', 'M9 18V5L20 3V16'.c(6.5, 18, 2.5).c(17.5, 16, 2.5));
ic('mic', 'social', 'microphone,voice,podcast,record,audio', rr(9, 3, 6, 11, 3).'M5.5 11C5.5 14.9 8.4 18 12 18C15.6 18 18.5 14.9 18.5 11M12 18V21M9 21H15');
ic('image', 'social', 'photo,picture,gallery,upload', rr(3, 4, 18, 16, 2).c(8.5, 9.5, 1.8).'M3 17L8.5 12L13 16L16 13.5L21 18');
ic('smile', 'social', 'happy,face,emoji,friendly', c(12, 12, 9).'M8 14C9 16 10.4 17 12 17C13.6 17 15 16 16 14'.dot(9, 9.5).dot(15, 9.5));
ic('hashtag', 'social', 'tag,topic,trending,social', 'M9.5 4L7.5 20M16.5 4L14.5 20M4 9H20M4 15H20');

// ---- services -------------------------------------------------------------------------------
ic('wrench', 'services', 'repair,tools,fix,maintenance', 'M15.5 3.5C13.2 3.5 11.5 5.3 11.5 7.5C11.5 8.2 11.7 8.8 12 9.4L4 17.4C3.3 18.1 3.3 19.2 4 19.9C4.7 20.6 5.8 20.6 6.5 19.9L14.5 11.9C15.1 12.2 15.7 12.4 16.4 12.4C18.6 12.4 20.5 10.7 20.5 8.4C20.5 8 20.4 7.6 20.3 7.2L17.5 10L15 9L14 6.5L16.8 3.7C16.4 3.6 16 3.5 15.5 3.5Z');
ic('car', 'services', 'vehicle,auto,drive,garage', 'M4 17V13L6 8C6.3 7.4 6.9 7 7.5 7H16.5C17.1 7 17.7 7.4 18 8L20 13V17ZM4 13H20'.c(7.5, 17.5, 1.8).c(16.5, 17.5, 1.8));
ic('scissors', 'services', 'cut,salon,hair,tailor', c(6, 6, 2.5).c(6, 18, 2.5).'M8 7.5L20 18M8 16.5L20 6');
ic('house', 'services', 'home,property,real-estate,building', 'M3 11L12 3L21 11M5 9.5V20H19V9.5M10 20V14H14V20');
ic('brush', 'services', 'paint,art,decor,creative', 'M20 4L11 13M11 13C9 11 6.5 12.5 6.5 15C6.5 17 5 18.5 3.5 19C5 20.5 8.5 21 10.5 19C12.5 17.5 13 15 11 13Z');
ic('tooth', 'services', 'dentist,dental,health,clinic', 'M12 6C10.5 4.5 7.5 4 6 5.5C4.3 7.2 4.5 10 5.5 12.5C6.5 15 7 19 8 20.5C9.2 21.5 10 19 10.5 16C10.8 14.8 11.3 14.2 12 14.2C12.7 14.2 13.2 14.8 13.5 16C14 19 14.8 21.5 16 20.5C17 19 17.5 15 18.5 12.5C19.5 10 19.7 7.2 18 5.5C16.5 4 13.5 4.5 12 6Z');
ic('dumbbell', 'services', 'gym,fitness,training,health', 'M2.5 10V14M6 7.5V16.5M18 7.5V16.5M21.5 10V14M6 12H18');
ic('paw', 'services', 'pet,animal,vet,dog', 'M12 12.5C9.5 12.5 7.5 15 7.5 17.2C7.5 19 9 20 10.5 19.6C11.4 19.4 11.7 19.2 12 19.2C12.3 19.2 12.6 19.4 13.5 19.6C15 20 16.5 19 16.5 17.2C16.5 15 14.5 12.5 12 12.5Z'.e(5.5, 11.5, 1.6, 2.2).e(9.5, 7, 1.7, 2.4).e(14.5, 7, 1.7, 2.4).e(18.5, 11.5, 1.6, 2.2));
ic('hammer', 'services', 'build,construction,repair,tools', 'M11 6.5L15 3L21 9L17.5 13ZM14.5 10.5L4.5 20.5');
ic('bolt', 'services', 'electric,power,energy,fast', 'M13 2.5L4.5 13.5H11.5L10.5 21.5L19.5 10H12.5Z');
ic('droplet', 'services', 'water,plumbing,clean,liquid', 'M12 3C12 3 5.5 10 5.5 14.5C5.5 18.1 8.4 21 12 21C15.6 21 18.5 18.1 18.5 14.5C18.5 10 12 3 12 3Z');
ic('plug', 'services', 'electrical,power,socket,charge', 'M9 3V8M15 3V8M6 8H18V12C18 15.3 15.3 18 12 18C8.7 18 6 15.3 6 12ZM12 18V21');
ic('paint-roller', 'services', 'painting,decorating,walls,home', rr(4, 4, 14, 5, 1.5).'M18 6.5H20V12H11V15'.rr(9.5, 15, 3, 6, 1));
ic('heartbeat', 'services', 'health,medical,doctor,healthcare,pulse,clinic', 'M3 12H8L10 7L14 17L16 12H21');
ic('graduation-cap', 'services', 'education,school,graduate,student,learning,course', 'M12 4L22 9L12 14L2 9ZM6 11.5V16C6 17.5 8.7 19 12 19C15.3 19 18 17.5 18 16V11.5M22 9V15');
ic('book', 'services', 'read,learning,library,guide', 'M12 6.5C12 5.4 12.9 4.5 14 4.5H20V18.5H14C12.9 18.5 12 19 12 20C12 19 11.1 18.5 10 18.5H4V4.5H10C11.1 4.5 12 5.4 12 6.5ZM12 6.5V20');
ic('bike', 'services', 'cycle,sport,delivery,transport', c(6, 16, 4).c(18, 16, 4).'M6 16L9.5 9H14L18 16M9.5 9L12 16H6M8 6H11M14 9L13 6H15.5');
ic('airplane', 'services', 'flight,travel,trip,holiday', 'M12 3C12.8 3 13.5 3.7 13.5 4.5V9L21 13.5V15.5L13.5 13.5V18L16 19.8V21L12 20L8 21V19.8L10.5 18V13.5L3 15.5V13.5L10.5 9V4.5C10.5 3.7 11.2 3 12 3Z');
ic('laptop', 'services', 'computer,work,tech,it', rr(5, 5, 14, 10, 1.5).'M3 19H21L19 15H5Z');
ic('monitor', 'services', 'screen,computer,display,desktop', rr(3, 4, 18, 12, 2).'M9 20H15M12 16V20');
ic('smartphone', 'services', 'mobile,phone,app,device', rr(7, 2.5, 10, 19, 2).'M10.5 18.5H13.5');

// ---- arrows ---------------------------------------------------------------------------------
ic('arrow-up', 'arrows', 'up,direction,rise,top', 'M12 20V4M5.5 10.5L12 4L18.5 10.5');
ic('arrow-down', 'arrows', 'down,direction,drop,bottom', 'M12 4V20M5.5 13.5L12 20L18.5 13.5');
ic('arrow-left', 'arrows', 'left,back,previous,direction', 'M20 12H4M10.5 5.5L4 12L10.5 18.5');
ic('arrow-right', 'arrows', 'right,next,forward,direction', 'M4 12H20M13.5 5.5L20 12L13.5 18.5');
ic('arrow-up-right', 'arrows', 'diagonal,open,external,growth', 'M6 18L18 6M8.5 6H18V15.5');
ic('arrow-down-right', 'arrows', 'diagonal,decline,direction,corner', 'M6 6L18 18M18 8.5V18H8.5');
ic('chevron-up', 'arrows', 'up,collapse,expand,more', 'M5 15L12 8L19 15');
ic('chevron-down', 'arrows', 'down,dropdown,expand,more', 'M5 9L12 16L19 9');
ic('chevron-left', 'arrows', 'left,back,previous,less', 'M15 5L8 12L15 19');
ic('chevron-right', 'arrows', 'right,next,forward,more', 'M9 5L16 12L9 19');
ic('chevrons-right', 'arrows', 'fast-forward,next,skip,double', 'M6 6L12 12L6 18M13 6L19 12L13 18');
ic('refresh', 'arrows', 'reload,sync,repeat,update', 'M4 12C4 16.4 7.6 20 12 20C14.7 20 17.1 18.6 18.5 16.5M18.5 20.5V16.5H14.5M20 12C20 7.6 16.4 4 12 4C9.3 4 6.9 5.4 5.5 7.5M5.5 3.5V7.5H9.5');
ic('undo', 'arrows', 'back,revert,return,reply', 'M4 9H15C18 9 20 11 20 14C20 17 18 19 15 19H8M8 5L4 9L8 13');
ic('trending-up', 'arrows', 'growth,rise,profit,increase', 'M3 17L9 11L13 15L21 7M15 7H21V13');
ic('trending-down', 'arrows', 'decline,fall,loss,decrease', 'M3 7L9 13L13 9L21 17M15 17H21V11');
ic('arrow-circle-right', 'arrows', 'next,go,continue,forward', c(12, 12, 9).'M8 12H16M13 9L16 12L13 15');
ic('download', 'arrows', 'save,get,import,file', 'M12 4V15M7.5 10.5L12 15L16.5 10.5M4 20H20');
ic('upload', 'arrows', 'send,share,export,file', 'M12 15V4M7.5 8.5L12 4L16.5 8.5M4 20H20');
ic('external-link', 'arrows', 'open,new-tab,link,visit', 'M14 4H20V10M20 4L11 13M18 14V19C18 19.6 17.6 20 17 20H5C4.4 20 4 19.6 4 19V7C4 6.4 4.4 6 5 6H10');
ic('expand', 'arrows', 'fullscreen,enlarge,corners,resize', 'M4 9V4H9M15 4H20V9M20 15V20H15M9 20H4V15');
ic('swap', 'arrows', 'exchange,switch,transfer,compare', 'M4 8H20M16 4L20 8L16 12M20 16H4M8 12L4 16L8 20');

// ---- shapes ---------------------------------------------------------------------------------
ic('shape-circle', 'shapes', 'round,dot,ellipse,basic', c(12, 12, 9));
ic('shape-square', 'shapes', 'box,rectangle,basic,block', rr(4, 4, 16, 16, 1));
ic('shape-rounded-square', 'shapes', 'app,tile,soft,rounded', rr(3.5, 3.5, 17, 17, 5));
ic('shape-triangle', 'shapes', 'warning,delta,play,basic', 'M12 4L21 19H3Z');
ic('shape-diamond', 'shapes', 'rhombus,gem,basic,decision', 'M12 3L21 12L12 21L3 12Z');
ic('shape-hexagon', 'shapes', 'honeycomb,badge,basic,six', 'M12 3L20 7.5V16.5L12 21L4 16.5V7.5Z');
ic('shape-pentagon', 'shapes', 'five,badge,basic,home', 'M12 3L21 9.8L17.5 20.5H6.5L3 9.8Z');
ic('shape-octagon', 'shapes', 'stop,sign,eight,basic', 'M8.5 3H15.5L21 8.5V15.5L15.5 21H8.5L3 15.5V8.5Z');
ic('shape-ring', 'shapes', 'donut,circle,outline,target', c(12, 12, 9).c(12, 12, 4.5));
ic('shape-cross', 'shapes', 'plus,medical,add,basic', 'M9 3H15V9H21V15H15V21H9V15H3V9H9Z');
ic('shape-semicircle', 'shapes', 'half,arch,rainbow,basic', 'M3 17C3 11 7 7 12 7C17 7 21 11 21 17Z');
ic('shape-burst', 'shapes', 'sale,badge,explosion,seal', starPath(12, 12, 9.5, 6.8, 12));
ic('shape-parallelogram', 'shapes', 'slant,speed,basic,italic', 'M7 5H21L17 19H3Z');

// ---- symbols --------------------------------------------------------------------------------
ic('plus', 'symbols', 'add,new,more,create', 'M12 5V19M5 12H19');
ic('minus', 'symbols', 'remove,less,subtract,dash', 'M5 12H19');
ic('close', 'symbols', 'x,cancel,delete,dismiss', 'M6 6L18 18M18 6L6 18');
ic('info', 'symbols', 'information,help,about,details', c(12, 12, 9).'M12 11V16'.dot(12, 8));
ic('question', 'symbols', 'help,faq,support,unknown', c(12, 12, 9).'M9.5 9.5C9.5 8.1 10.6 7.2 12 7.2C13.4 7.2 14.5 8.1 14.5 9.4C14.5 11.3 12 11.5 12 13.5'.dot(12, 16.5));
ic('alert', 'symbols', 'warning,caution,notice,attention', 'M12 3.5L21.5 20H2.5ZM12 10V14.5'.dot(12, 17.3));
ic('star', 'symbols', 'rating,favourite,review,quality', 'M12 3L14.8 8.9L21 9.7L16.5 14L17.7 20.5L12 17.3L6.3 20.5L7.5 14L3 9.7L9.2 8.9Z');
ic('sparkle', 'symbols', 'new,magic,shine,special', 'M12 3C12.5 8 13 10.5 21 12C13 13.5 12.5 16 12 21C11.5 16 11 13.5 3 12C11 10.5 11.5 8 12 3Z');
ic('sun', 'symbols', 'day,light,summer,weather', c(12, 12, 4).'M12 2.5V5M12 19V21.5M2.5 12H5M19 12H21.5M5.3 5.3L7 7M17 17L18.7 18.7M5.3 18.7L7 17M17 7L18.7 5.3');
ic('moon', 'symbols', 'night,dark,sleep,evening', 'M20 14.5C19 15 17.8 15.3 16.5 15.3C12.4 15.3 9 12 9 7.8C9 6 9.6 4.3 10.7 3C6.4 3.9 3.5 7.6 3.5 12C3.5 16.9 7.5 20.8 12.5 20.8C16 20.8 19 18.6 20 14.5Z');
ic('cloud', 'symbols', 'weather,storage,online,sky', 'M7 18C4.2 18 2.5 16 2.5 13.5C2.5 11.2 4.2 9.4 6.4 9.1C7.2 6.4 9.5 4.5 12.5 4.5C15.6 4.5 18 6.8 18.4 9.8C20.4 10.2 21.5 11.8 21.5 13.8C21.5 16.2 19.8 18 17.5 18Z');
ic('umbrella', 'symbols', 'rain,insurance,cover,protect', 'M3 12C3 7 7 3.5 12 3.5C17 3.5 21 7 21 12ZM12 12V18C12 19.7 10.7 20.5 9.5 20.5C8.5 20.5 7.8 19.9 7.5 19.2');
ic('flower', 'symbols', 'floral,garden,nature,spring', c(12, 12, 2.4).e(12, 6.2, 2.4, 3.4).e(12, 17.8, 2.4, 3.4).e(6.2, 12, 3.4, 2.4).e(17.8, 12, 3.4, 2.4));
ic('infinity', 'symbols', 'loop,unlimited,forever,endless', 'M12 12C14 8 17.5 7 19.5 8.5C21.5 10 21.5 14 19.5 15.5C17.5 17 14 16 12 12C10 8 6.5 7 4.5 8.5C2.5 10 2.5 14 4.5 15.5C6.5 17 10 16 12 12Z');
ic('menu', 'symbols', 'hamburger,navigation,list,lines', 'M4 6H20M4 12H20M4 18H20');
ic('search', 'symbols', 'find,magnifier,look,zoom', c(10.5, 10.5, 6.5).'M15.5 15.5L20.5 20.5');
ic('dots', 'symbols', 'more,options,menu,ellipsis', dot(5, 12).dot(12, 12).dot(19, 12));
ic('trash', 'symbols', 'delete,bin,remove,waste', 'M4 7H20M9 7V4.5H15V7M6 7L7 20H17L18 7M10 11V16M14 11V16');
ic('pencil', 'symbols', 'edit,write,draw,change', 'M4 20L5 15L16.5 3.5L20.5 7.5L9 19ZM14 6L18 10');

// ---- checks ---------------------------------------------------------------------------------
ic('check', 'checks', 'tick,done,yes,confirm', 'M4.5 12.5L9.5 17.5L19.5 7');
ic('check-circle', 'checks', 'tick,done,success,confirm', c(12, 12, 9).$check);
ic('check-double', 'checks', 'tick,read,done,delivered', 'M2 13L6.5 17.5L15 8M10.5 14.5L12.5 17L21.5 7.5');
ic('x-circle', 'checks', 'cancel,error,wrong,no', c(12, 12, 9).'M9 9L15 15M15 9L9 15');
ic('shield-check', 'checks', 'secure,verified,safe,protected', 'M12 3L20 6V12C20 16.5 16.5 19.7 12 21C7.5 19.7 4 16.5 4 12V6Z'.$check);
ic('badge-check', 'checks', 'verified,quality,approved,seal', starPath(12, 12, 9.5, 8, 10).$check);
ic('list-check', 'checks', 'checklist,tasks,todo,done', 'M10 6H21M10 12H21M10 18H21M3 6L4.5 7.5L7 4.5M3 12L4.5 13.5L7 10.5M3 18L4.5 19.5L7 16.5');
ic('checkbox', 'checks', 'tick,form,select,done', rr(4, 4, 16, 16, 3).'M8.5 12.5L11 15L16 9');
ic('checkbox-empty', 'checks', 'form,select,todo,empty', rr(4, 4, 16, 16, 3));
ic('toggle-on', 'checks', 'switch,on,setting,enabled', rr(2, 7, 20, 10, 5).c(16.5, 12, 2.5));

// ---- added in the library QA pass: words owners search for that had no icon -----------------------
ic('tree', 'services', 'plant,garden,nature,landscaping,park', 'M12 3L17.5 11H14.5L19 17H5L9.5 11H6.5ZM12 17V21');
ic('bed', 'services', 'hotel,sleep,accommodation,guest house,bnb', 'M3 5V20M3 15H21V20M21 15V11C21 9.9 20.1 9 19 9H11V15'.c(7, 11.5, 2.2));
ic('wifi', 'services', 'internet,network,online,signal,connection', 'M2.5 9C8 4 16 4 21.5 9M5.5 12.5C9 9.5 15 9.5 18.5 12.5M8.5 16C10.5 14.3 13.5 14.3 15.5 16'.dot(12, 19.5));
ic('crown', 'symbols', 'king,queen,premium,royal,best', 'M4 18L3 7L8 11L12 5L16 11L21 7L20 18ZM4 21H20');
ic('printer', 'business', 'print,printing,copy,paper,office', 'M7 8V3H17V8M7 17H4V9H20V17H17M7 14H17V21H7Z');
ic('paperclip', 'business', 'attach,attachment,clip,file', 'M19 11L12 18C9.8 20.2 6.2 20.2 4.3 18.3C2.4 16.4 2.4 12.8 4.6 10.6L12.6 2.6C14 1.2 16.3 1.2 17.7 2.6C19.1 4 19.1 6.3 17.7 7.7L9.7 15.7C8.9 16.5 7.6 16.5 6.8 15.7C6 14.9 6 13.6 6.8 12.8L13.5 6.1');

// ---- R-45 polish (ptpl): search words, two category moves and the order the picker shows ------------------
// The jury called the icon grid "an unsorted wall of glyphs". The file now lists icons grouped in the picker's own chip order,
// the most useful ones first inside each group, so the "All" view reads as sections instead of an alphabet.
$moreTags = [
    'document' => 'agreement,contract,form,paperwork', 'award' => 'certificate,medal,winner', 'trophy' => 'winner,champion,competition',
    'brush' => 'beauty,makeup,artist', 'scissors' => 'beauty,haircut,barber,salon', 'play' => 'game,start,watch', 'smartphone' => 'tablet,device,app',
    'monitor' => 'tv,television,screen,display', 'globe' => 'language,translate,world,international', 'bolt' => 'battery,charge,electric,energy',
    'eye' => 'hide,show,view,visible', 'lock' => 'unlock,closed,secure', 'chat-bubble' => 'speech,quote,say', 'megaphone' => 'speech,announce,promote,shout',
    'dumbbell' => 'sports,run,training,exercise,workout', 'bike' => 'sports,run,cycle,ride', 'fork-knife' => 'chicken,fish,meal,dinner,lunch', 'bowl' => 'soup,vegetable,salad,rice',
    'apple' => 'vegetable,healthy,fruit,grocer', 'cupcake' => 'bread,bakery,dessert,treat', 'house' => 'door,property,estate,rent', 'bank' => 'loan,savings,finance',
    'coin' => 'loan,change,saving', 'location-arrow' => 'compass,navigate,direction,gps', 'users' => 'handshake,meeting,partners,community', 'smile' => 'kids,happy,baby,friendly',
    'gift' => 'toy,present,birthday,surprise', 'paw' => 'dog,cat,pet,animal,vet',
];
foreach ($moreTags as $key => $list) {
    if (isset($I[$key])) {
        $I[$key]['tags'] = array_values(array_unique([...$I[$key]['tags'], ...array_map('trim', explode(',', $list))]));
    }
}
foreach (['leaf' => 'symbols', 'flame' => 'symbols'] as $key => $cat) {
    $I[$key]['category'] = $cat;
}
$groupOrder = ['business', 'services', 'arrows', 'symbols', 'contact', 'food', 'commerce', 'social', 'shapes', 'checks'];
$first = [
    'business' => ['users', 'user', 'briefcase', 'building', 'store', 'chart-line', 'target', 'lightbulb', 'rocket', 'trophy'],
    'services' => ['house', 'car', 'wrench', 'hammer', 'scissors', 'brush', 'laptop', 'graduation-cap', 'tooth', 'dumbbell'],
    'arrows' => ['arrow-right', 'arrow-left', 'arrow-up', 'arrow-down', 'chevron-right', 'chevron-down'],
    'symbols' => ['star', 'sparkle', 'crown', 'search', 'plus', 'close', 'info', 'question', 'alert', 'leaf', 'flame'],
    'contact' => ['phone', 'mail', 'chat-whatsapp', 'pin', 'globe', 'clock', 'calendar', 'at-sign'],
    'food' => ['fork-knife', 'coffee-cup', 'pizza', 'burger', 'cake', 'cupcake', 'wine-glass', 'beer'],
    'commerce' => ['cart', 'bag', 'tag', 'percent', 'gift', 'credit-card', 'receipt', 'truck'],
    'social' => ['heart', 'thumbs-up', 'camera', 'share', 'megaphone', 'play', 'message-circle', 'video'],
    'shapes' => ['shape-circle', 'shape-square', 'shape-triangle', 'shape-rounded-square', 'shape-diamond', 'shape-burst'],
    'checks' => ['check', 'check-circle', 'x-circle', 'shield-check', 'badge-check', 'list-check'],
];
$rank = static function (string $key) use ($I, $first, $groupOrder): array {
    $cat = $I[$key]['category'];
    $pos = array_search($key, $first[$cat] ?? [], true);

    return [array_search($cat, $groupOrder, true), $pos === false ? 999 : $pos, $key];
};
uksort($I, static fn ($a, $b) => $rank($a) <=> $rank($b));

// ---------------------------------------------------------------------------------------------
$out = ['$comment' => 'Dot.Design built-in icon set. Own-drawn for this repository on a 24x24 grid in a stroke style (stroke-linecap/linejoin round, no fill). Path data uses only the commands M L H V C S Q T Z. Licence: MIT, drawn for this repo (see LICENSE); no third-party artwork. Regenerate with scripts/dev/build-icons.php.'] + $I;
file_put_contents(dirname(__DIR__, 2).'/resources/design/icons.json', json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
fwrite(STDOUT, count($I)." icons written\n");
