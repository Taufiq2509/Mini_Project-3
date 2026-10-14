<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/config.php';

function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf(): string {
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return '<input type="hidden" name="_csrf" value="'.e($_SESSION['_csrf']).'">';
}
function verify_csrf(): void {
    if (!hash_equals($_SESSION['_csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        http_response_code(419); exit('Token form tidak valid. Muat ulang halaman.');
    }
}
function user(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): void {
    if (!user()) { header('Location: ?page=login'); exit; }
}
function require_admin(): void {
    require_login();
    if (user()['role'] !== 'admin') { http_response_code(403); exit('403 Forbidden: hanya admin yang boleh melakukan tindakan ini.'); }
}
function flash(string $msg): void { $_SESSION['_flash'] = $msg; }
function redirect(string $page): never { header('Location: ?page='.rawurlencode($page)); exit; }
function post_string(string $key, int $max=255): string {
    $v = trim((string)($_POST[$key] ?? ''));
    if ($v === '' || strlen($v) > $max) throw new RuntimeException("Kolom {$key} wajib diisi dan maksimal {$max} karakter.");
    return $v;
}
function positive_int(string $key, bool $allowZero=false): int {
    $v = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT);
    if ($v === false || ($allowZero ? $v < 0 : $v <= 0)) throw new RuntimeException("Kolom {$key} harus berupa angka yang valid.");
    return $v;
}

$page = (string)($_GET['page'] ?? (user() ? 'dashboard' : 'login'));
$action = (string)($_POST['action'] ?? '');
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        if ($action === 'login') {
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $stmt = $pdo->prepare('SELECT id, username, password_hash, role FROM users WHERE username = :username LIMIT 1');
            $stmt->execute(['username'=>$username]); $u = $stmt->fetch();
            if (!$u || !password_verify($password, $u['password_hash'])) throw new RuntimeException('Username atau password salah.');
            session_regenerate_id(true);
            $_SESSION['user'] = ['id'=>(int)$u['id'],'username'=>$u['username'],'role'=>$u['role']];
            redirect('dashboard');
        }
        if ($action === 'logout') { $_SESSION=[]; session_regenerate_id(true); redirect('login'); }
        require_login();
        if (in_array($action, ['category_save','category_delete','product_save','product_delete','supplier_save','supplier_delete'], true)) require_admin();

        if ($action === 'category_save') {
            $id = (int)($_POST['id'] ?? 0); $name=post_string('name',100); $desc=trim((string)($_POST['description']??''));
            if ($id) $st=$pdo->prepare('UPDATE categories SET name=?, description=? WHERE id=?');
            else $st=$pdo->prepare('INSERT INTO categories(name,description) VALUES(?,?)');
            $id ? $st->execute([$name,$desc ?: null,$id]) : $st->execute([$name,$desc ?: null]);
            flash('Kategori berhasil disimpan.'); redirect('categories');
        }
        if ($action === 'category_delete') {
            $st=$pdo->prepare('DELETE FROM categories WHERE id=?'); $st->execute([positive_int('id')]);
            flash('Kategori berhasil dihapus.'); redirect('categories');
        }
        if ($action === 'supplier_save') {
            $id=(int)($_POST['id']??0); $name=post_string('name',150);
            $phone=trim((string)($_POST['phone']??'')); $email=trim((string)($_POST['email']??'')); $address=trim((string)($_POST['address']??''));
            if ($email !== '' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Format email supplier tidak valid.');
            $values=[$name,$phone?:null,$email?:null,$address?:null];
            if($id){$st=$pdo->prepare('UPDATE suppliers SET name=?,phone=?,email=?,address=? WHERE id=?');$st->execute([...$values,$id]);}
            else{$st=$pdo->prepare('INSERT INTO suppliers(name,phone,email,address) VALUES(?,?,?,?)');$st->execute($values);}
            flash('Supplier berhasil disimpan.'); redirect('suppliers');
        }
        if ($action === 'supplier_delete') {
            $st=$pdo->prepare('DELETE FROM suppliers WHERE id=?');$st->execute([positive_int('id')]);
            flash('Supplier berhasil dihapus.');redirect('suppliers');
        }
        if ($action === 'product_save') {
            $id=(int)($_POST['id']??0); $category=positive_int('category_id'); $sku=strtoupper(post_string('sku',50)); $name=post_string('name',150);
            $price=filter_var($_POST['price']??null,FILTER_VALIDATE_FLOAT); if($price===false||$price<0) throw new RuntimeException('Harga harus angka nol atau lebih.');
            $stock=positive_int('stock',true); $min=positive_int('minimum_stock',true);
            $suppliers=array_values(array_unique(array_filter(array_map('intval',$_POST['suppliers']??[]),fn($v)=>$v>0)));
            $pdo->beginTransaction();
            if($id){
                $old=$pdo->prepare('SELECT stock FROM products WHERE id=? FOR UPDATE');$old->execute([$id]);$oldStock=$old->fetchColumn();
                if($oldStock===false) throw new RuntimeException('Produk tidak ditemukan.');
                if((int)$oldStock !== $stock) {
                    // Stok yang sudah memiliki histori hanya boleh diubah lewat transaksi agar histori konsisten.
                    $cnt=$pdo->prepare('SELECT COUNT(*) FROM stock_movements WHERE product_id=?');$cnt->execute([$id]);
                    if((int)$cnt->fetchColumn()>0) throw new RuntimeException('Stok produk dengan histori tidak boleh diedit langsung. Gunakan menu Transaksi Stok.');
                }
                $st=$pdo->prepare('UPDATE products SET category_id=?,sku=?,name=?,price=?,stock=?,minimum_stock=? WHERE id=?');
                $st->execute([$category,$sku,$name,$price,$stock,$min,$id]);
                $pdo->prepare('DELETE FROM product_suppliers WHERE product_id=?')->execute([$id]);
                $productId=$id;
            } else {
                $st=$pdo->prepare('INSERT INTO products(category_id,sku,name,price,stock,minimum_stock) VALUES(?,?,?,?,?,?)');
                $st->execute([$category,$sku,$name,$price,$stock,$min]); $productId=(int)$pdo->lastInsertId();
            }
            $link=$pdo->prepare('INSERT INTO product_suppliers(product_id,supplier_id,supplier_price) VALUES(?,?,NULL)');
            foreach($suppliers as $sid) $link->execute([$productId,$sid]);
            $pdo->commit(); flash('Produk berhasil disimpan.'); redirect('products');
        }
        if ($action === 'product_delete') {
            $id=positive_int('id');
            $st=$pdo->prepare('DELETE FROM products WHERE id=?');$st->execute([$id]);
            flash('Produk berhasil dihapus.');redirect('products');
        }
        if ($action === 'stock_save') {
            $pid=positive_int('product_id');$type=(string)($_POST['type']??'');if(!in_array($type,['IN','OUT'],true))throw new RuntimeException('Jenis transaksi tidak valid.');
            $qty=positive_int('quantity');$note=trim((string)($_POST['note']??''));if(strlen($note)>255)throw new RuntimeException('Catatan maksimal 255 karakter.');
            $pdo->beginTransaction();
            $st=$pdo->prepare('SELECT stock FROM products WHERE id=? FOR UPDATE');$st->execute([$pid]);$stock=$st->fetchColumn();
            if($stock===false)throw new RuntimeException('Produk tidak ditemukan.');
            if($type==='OUT' && $qty>(int)$stock)throw new RuntimeException('Stok tidak mencukupi. Stok tersedia: '.$stock.'.');
            $newStock=$type==='IN'?(int)$stock+$qty:(int)$stock-$qty;
            $pdo->prepare('UPDATE products SET stock=? WHERE id=?')->execute([$newStock,$pid]);
            $pdo->prepare('INSERT INTO stock_movements(product_id,user_id,type,quantity,note) VALUES(?,?,?,?,?)')->execute([$pid,user()['id'],$type,$qty,$note?:null]);
            $pdo->commit();flash('Transaksi stok tersimpan dan histori diperbarui.');redirect('stock');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $ex instanceof PDOException && str_contains($ex->getMessage(),'Duplicate') ? 'Data duplikat. Pastikan nama kategori atau SKU belum digunakan.' : $ex->getMessage();
    }
}
if ($page !== 'login') require_login();
if ($page === 'login' && user()) redirect('dashboard');
$flash=$_SESSION['_flash']??null;unset($_SESSION['_flash']);

function fetch_all(string $sql, array $params=[]): array { global $pdo; $s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll(); }
function fetch_one(string $sql,array $params=[]): ?array { $rows=fetch_all($sql,$params);return $rows[0]??null; }

$edit = null;
if (isset($_GET['edit'])) {
    require_login();
    $kind=(string)($_GET['page']??'');
    if(in_array($kind,['categories','products','suppliers'],true) && user()['role']==='admin') {
        $table=['categories'=>'categories','products'=>'products','suppliers'=>'suppliers'][$kind];
        $edit=fetch_one("SELECT * FROM {$table} WHERE id=? ",[(int)$_GET['edit']]);
        if($kind==='products' && $edit) $edit['suppliers']=array_column(fetch_all('SELECT supplier_id FROM product_suppliers WHERE product_id=?',[$edit['id']]),'supplier_id');
    }
}
$categories=fetch_all('SELECT * FROM categories ORDER BY name');
$suppliers=fetch_all('SELECT * FROM suppliers ORDER BY name');
?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Inventory Management System</title>
<style>
:root{font-family:Arial,sans-serif;color:#1f2937;background:#f3f6fb}*{box-sizing:border-box}body{margin:0}.top{background:#123b63;color:white;padding:18px 24px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}.top h1{font-size:22px;margin:0}.wrap{max-width:1200px;margin:24px auto;padding:0 16px}.nav{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}.nav a,.btn{background:#155e95;color:white;padding:9px 13px;border-radius:7px;text-decoration:none;border:0;cursor:pointer;display:inline-block}.btn.secondary{background:#64748b}.btn.danger{background:#b91c1c}.btn.light{background:#e2e8f0;color:#1f2937}.panel,.stat{background:white;padding:18px;border-radius:12px;box-shadow:0 2px 10px #0f172a0d;margin-bottom:18px}.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px}.stat strong{font-size:26px;display:block;margin-top:8px}.formgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}label{display:block;font-size:13px;font-weight:bold;margin-bottom:5px}input,select,textarea{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:7px;margin-bottom:12px}input[type=checkbox]{width:auto}table{width:100%;border-collapse:collapse;background:white}th,td{text-align:left;padding:10px;border-bottom:1px solid #e2e8f0;vertical-align:top}th{background:#eaf0f7} .tablewrap{overflow-x:auto}.alert{padding:12px;border-radius:8px;margin-bottom:15px;background:#dcfce7;color:#166534}.error{background:#fee2e2;color:#991b1b}.muted{color:#64748b;font-size:13px}.actions{display:flex;gap:6px;flex-wrap:wrap}.inline{display:inline}.low{color:#b91c1c;font-weight:bold}.login{max-width:420px;margin:8vh auto}.small{font-size:12px}
</style></head><body>
<div class="top"><h1>Inventory Management System</h1><div><?php if(user()): ?>Masuk sebagai <b><?=e(user()['username'])?></b> (<?=e(user()['role'])?>) <form class="inline" method="post"><?=csrf()?><input type="hidden" name="action" value="logout"><button class="btn secondary">Keluar</button></form><?php endif; ?></div></div>
<div class="wrap">
<?php if($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
<?php if($flash): ?><div class="alert"><?=e($flash)?></div><?php endif; ?>
<?php if($page==='login'): ?>
<div class="panel login"><h2>Login</h2><p class="muted">Gunakan akun demo di bawah untuk mencoba aplikasi.</p><form method="post"><?=csrf()?><input type="hidden" name="action" value="login"><label>Username</label><input name="username" required autocomplete="username"><label>Password</label><input name="password" type="password" required autocomplete="current-password"><button class="btn">Masuk</button></form><p class="small">Admin: admin / password<br>Staff: staff / password</p></div>
<?php else: ?>
<div class="nav"><a href="?page=dashboard">Dashboard</a><a href="?page=products">Produk</a><a href="?page=categories">Kategori</a><a href="?page=suppliers">Supplier</a><a href="?page=stock">Transaksi Stok</a></div>
<?php if($page==='dashboard'):
$stats=fetch_one("SELECT COUNT(*) total_products, COALESCE(SUM(stock*price),0) inventory_value, SUM(stock<=minimum_stock) low_stock FROM products");
$mov=fetch_one("SELECT COALESCE(SUM(CASE WHEN type='IN' THEN quantity ELSE 0 END),0) total_in, COALESCE(SUM(CASE WHEN type='OUT' THEN quantity ELSE 0 END),0) total_out FROM stock_movements");
$low=fetch_all('SELECT sku,name,stock,minimum_stock FROM products WHERE stock<=minimum_stock ORDER BY stock ASC');
?>
<h2>Dashboard</h2><div class="stats"><div class="stat">Total Produk<strong><?=e($stats['total_products'])?></strong></div><div class="stat">Nilai Persediaan<strong>Rp <?=number_format((float)$stats['inventory_value'],0,',','.')?></strong></div><div class="stat">Produk Stok Rendah<strong><?=e($stats['low_stock']??0)?></strong></div><div class="stat">Barang Masuk / Keluar<strong><?=e($mov['total_in']??0)?> / <?=e($mov['total_out']??0)?></strong></div></div>
<div class="panel"><h3>Produk dengan Stok Rendah</h3><div class="tablewrap"><table><tr><th>SKU</th><th>Nama</th><th>Stok</th><th>Minimum</th></tr><?php foreach($low as $p):?><tr><td><?=e($p['sku'])?></td><td><?=e($p['name'])?></td><td class="low"><?=e($p['stock'])?></td><td><?=e($p['minimum_stock'])?></td></tr><?php endforeach;?></table></div></div>
<?php elseif($page==='categories'): ?>
<h2>Data Kategori</h2><?php if(user()['role']==='admin'): ?><div class="panel"><h3><?= $edit?'Edit':'Tambah' ?> Kategori</h3><form method="post"><?=csrf()?><input type="hidden" name="action" value="category_save"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>"><div class="formgrid"><div><label>Nama kategori</label><input name="name" required maxlength="100" value="<?=e($edit['name']??'')?>"></div><div><label>Deskripsi</label><input name="description" maxlength="255" value="<?=e($edit['description']??'')?>"></div></div><button class="btn">Simpan</button><?php if($edit):?> <a class="btn light" href="?page=categories">Batal</a><?php endif;?></form></div><?php endif;?>
<div class="panel tablewrap"><table><tr><th>Nama</th><th>Deskripsi</th><th>Jumlah Produk</th><?php if(user()['role']==='admin'):?><th>Aksi</th><?php endif;?></tr><?php foreach(fetch_all('SELECT c.*,COUNT(p.id) product_count FROM categories c LEFT JOIN products p ON p.category_id=c.id GROUP BY c.id ORDER BY c.name') as $c):?><tr><td><?=e($c['name'])?></td><td><?=e($c['description'])?></td><td><?=e($c['product_count'])?></td><?php if(user()['role']==='admin'):?><td class="actions"><a class="btn" href="?page=categories&edit=<?=$c['id']?>">Edit</a><form method="post" onsubmit="return confirm('Hapus kategori?')"><?=csrf()?><input type="hidden" name="action" value="category_delete"><input type="hidden" name="id" value="<?=$c['id']?>"><button class="btn danger">Hapus</button></form></td><?php endif;?></tr><?php endforeach;?></table></div>
<?php elseif($page==='suppliers'): ?>
<h2>Data Supplier</h2><?php if(user()['role']==='admin'):?><div class="panel"><h3><?= $edit?'Edit':'Tambah' ?> Supplier</h3><form method="post"><?=csrf()?><input type="hidden" name="action" value="supplier_save"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>"><div class="formgrid"><div><label>Nama</label><input name="name" required value="<?=e($edit['name']??'')?>"></div><div><label>Telepon</label><input name="phone" value="<?=e($edit['phone']??'')?>"></div><div><label>Email</label><input type="email" name="email" value="<?=e($edit['email']??'')?>"></div><div><label>Alamat</label><input name="address" value="<?=e($edit['address']??'')?>"></div></div><button class="btn">Simpan</button><?php if($edit):?> <a class="btn light" href="?page=suppliers">Batal</a><?php endif;?></form></div><?php endif;?>
<div class="panel tablewrap"><table><tr><th>Nama</th><th>Telepon</th><th>Email</th><th>Alamat</th><?php if(user()['role']==='admin'):?><th>Aksi</th><?php endif;?></tr><?php foreach($suppliers as $s):?><tr><td><?=e($s['name'])?></td><td><?=e($s['phone'])?></td><td><?=e($s['email'])?></td><td><?=e($s['address'])?></td><?php if(user()['role']==='admin'):?><td class="actions"><a class="btn" href="?page=suppliers&edit=<?=$s['id']?>">Edit</a><form method="post" onsubmit="return confirm('Hapus supplier?')"><?=csrf()?><input type="hidden" name="action" value="supplier_delete"><input type="hidden" name="id" value="<?=$s['id']?>"><button class="btn danger">Hapus</button></form></td><?php endif;?></tr><?php endforeach;?></table></div>
<?php elseif($page==='products'):
$search=trim((string)($_GET['search']??''));$cat=(int)($_GET['category_id']??0);$sort=(string)($_GET['sort']??'name');$dir=strtolower((string)($_GET['direction']??'asc'))==='desc'?'DESC':'ASC';if(!in_array($sort,['name','sku','price','stock'],true))$sort='name';
$where=[];$params=[];if($search!==''){$where[]='(p.name LIKE ? OR p.sku LIKE ?)';$params[]='%'.$search.'%';$params[]='%'.$search.'%';}if($cat>0){$where[]='p.category_id=?';$params[]=$cat;}$w=$where?'WHERE '.implode(' AND ',$where):'';
$total=(int)fetch_one("SELECT COUNT(*) n FROM products p $w",$params)['n'];$perPage=10;$pages=max(1,(int)ceil($total/$perPage));$cur=max(1,min($pages,(int)($_GET['p']??1)));$offset=($cur-1)*$perPage;
$products=fetch_all("SELECT p.*,c.name category_name FROM products p JOIN categories c ON c.id=p.category_id $w ORDER BY p.$sort $dir LIMIT $perPage OFFSET $offset",$params);
?>
<h2>Data Produk</h2><?php if(user()['role']==='admin'):?><div class="panel"><h3><?= $edit?'Edit':'Tambah' ?> Produk</h3><form method="post"><?=csrf()?><input type="hidden" name="action" value="product_save"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>"><div class="formgrid"><div><label>SKU (unik)</label><input name="sku" required maxlength="50" value="<?=e($edit['sku']??'')?>"></div><div><label>Nama produk</label><input name="name" required maxlength="150" value="<?=e($edit['name']??'')?>"></div><div><label>Kategori</label><select name="category_id" required><option value="">Pilih kategori</option><?php foreach($categories as $c):?><option value="<?=$c['id']?>" <?=((int)($edit['category_id']??0)===(int)$c['id'])?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select></div><div><label>Harga</label><input name="price" type="number" min="0" step="0.01" required value="<?=e($edit['price']??'0')?>"></div><div><label>Stok awal (perubahan stok berikutnya melalui transaksi)</label><input name="stock" type="number" min="0" required value="<?=e($edit['stock']??'0')?>"></div><div><label>Minimum stok</label><input name="minimum_stock" type="number" min="0" required value="<?=e($edit['minimum_stock']??'5')?>"></div></div><label>Supplier (boleh pilih lebih dari satu)</label><?php foreach($suppliers as $s):?><label class="small"><input type="checkbox" name="suppliers[]" value="<?=$s['id']?>" <?=in_array((int)$s['id'],array_map('intval',$edit['suppliers']??[]),true)?'checked':''?>> <?=e($s['name'])?></label><?php endforeach;?><p><button class="btn">Simpan Produk</button><?php if($edit):?> <a class="btn light" href="?page=products">Batal</a><?php endif;?></p></form></div><?php endif;?>
<div class="panel"><form method="get"><input type="hidden" name="page" value="products"><div class="formgrid"><div><label>Cari nama / SKU</label><input name="search" value="<?=e($search)?>"></div><div><label>Filter kategori</label><select name="category_id"><option value="0">Semua kategori</option><?php foreach($categories as $c):?><option value="<?=$c['id']?>" <?=($cat===(int)$c['id'])?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select></div><div><label>Urutkan</label><select name="sort"><?php foreach(['name'=>'Nama','sku'=>'SKU','price'=>'Harga','stock'=>'Stok'] as $k=>$v):?><option value="<?=$k?>" <?=$sort===$k?'selected':''?>><?=$v?></option><?php endforeach;?></select></div><div><label>Arah</label><select name="direction"><option value="asc" <?=$dir==='ASC'?'selected':''?>>Naik</option><option value="desc" <?=$dir==='DESC'?'selected':''?>>Turun</option></select></div></div><button class="btn">Terapkan</button></form></div>
<div class="panel tablewrap"><table><tr><th>SKU</th><th>Produk</th><th>Kategori</th><th>Harga</th><th>Stok</th><th>Supplier</th><?php if(user()['role']==='admin'):?><th>Aksi</th><?php endif;?></tr><?php foreach($products as $p):$ps=fetch_all('SELECT s.name FROM product_suppliers ps JOIN suppliers s ON s.id=ps.supplier_id WHERE ps.product_id=?',[$p['id']]);?><tr><td><?=e($p['sku'])?></td><td><?=e($p['name'])?></td><td><?=e($p['category_name'])?></td><td>Rp <?=number_format((float)$p['price'],0,',','.')?></td><td class="<?=((int)$p['stock']<=(int)$p['minimum_stock'])?'low':''?>"><?=e($p['stock'])?></td><td><?=e(implode(', ',array_column($ps,'name')))?></td><?php if(user()['role']==='admin'):?><td class="actions"><a class="btn" href="?page=products&edit=<?=$p['id']?>">Edit</a><form method="post" onsubmit="return confirm('Hapus produk?')"><?=csrf()?><input type="hidden" name="action" value="product_delete"><input type="hidden" name="id" value="<?=$p['id']?>"><button class="btn danger">Hapus</button></form></td><?php endif;?></tr><?php endforeach;?></table><p>Halaman <?=$cur?> dari <?=$pages?> · Total <?=$total?> produk</p><div class="actions"><?php for($i=1;$i<=$pages;$i++):?><a class="btn <?=($i===$cur?'':'light')?>" href="?<?=e(http_build_query(['page'=>'products','search'=>$search,'category_id'=>$cat,'sort'=>$sort,'direction'=>strtolower($dir),'p'=>$i]))?>"><?=$i?></a><?php endfor;?></div></div>
<?php elseif($page==='stock'): ?>
<h2>Transaksi Barang Masuk / Keluar</h2><div class="panel"><form method="post"><?=csrf()?><input type="hidden" name="action" value="stock_save"><div class="formgrid"><div><label>Produk</label><select name="product_id" required><option value="">Pilih produk</option><?php foreach(fetch_all('SELECT id,sku,name,stock FROM products ORDER BY name') as $p):?><option value="<?=$p['id']?>"><?=e($p['sku'].' - '.$p['name'].' (stok '.$p['stock'].')')?></option><?php endforeach;?></select></div><div><label>Jenis transaksi</label><select name="type"><option value="IN">Barang Masuk</option><option value="OUT">Barang Keluar</option></select></div><div><label>Jumlah</label><input name="quantity" type="number" min="1" required></div><div><label>Catatan</label><input name="note" maxlength="255"></div></div><button class="btn">Simpan Transaksi</button></form></div>
<div class="panel tablewrap"><h3>Histori Transaksi</h3><table><tr><th>Waktu</th><th>Produk</th><th>Jenis</th><th>Jumlah</th><th>Petugas</th><th>Catatan</th></tr><?php foreach(fetch_all('SELECT sm.*,p.sku,p.name,u.username FROM stock_movements sm JOIN products p ON p.id=sm.product_id JOIN users u ON u.id=sm.user_id ORDER BY sm.id DESC LIMIT 100') as $m):?><tr><td><?=e($m['created_at'])?></td><td><?=e($m['sku'].' - '.$m['name'])?></td><td><?=e($m['type']==='IN'?'Masuk':'Keluar')?></td><td><?=e($m['quantity'])?></td><td><?=e($m['username'])?></td><td><?=e($m['note'])?></td></tr><?php endforeach;?></table></div>
<?php else: ?><div class="panel"><h2>Halaman tidak ditemukan</h2><a href="?page=dashboard" class="btn">Kembali</a></div><?php endif;?>
<?php endif; ?></div></body></html>
