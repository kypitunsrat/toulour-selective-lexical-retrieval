<?php
/** Helper khusus riset independen; tidak pernah memodifikasi database. */
final class IndependentBenchmarkCommon
{
    public static function readCsv(string $path, array $required): array
    {
        if (!is_file($path) || !is_readable($path)) throw new RuntimeException('CSV tidak ditemukan: '.$path);
        $f=fopen($path,'rb');
        if (!$f) throw new RuntimeException('Gagal membaca CSV');
        try {
            $header=fgetcsv($f);
            if (!$header) throw new RuntimeException('Header CSV kosong: '.$path);
            $header[0]=preg_replace('/^\xEF\xBB\xBF/','',$header[0]);
            if (count($header)!==count(array_unique($header))) throw new RuntimeException('Header CSV duplikat');
            foreach($required as $name) if (!in_array($name,$header,true)) throw new RuntimeException('Kolom diperlukan: '.$name.' pada '.$path);
            $out=[];$line=1;
            while(($cells=fgetcsv($f))!==false) {
                $line++;
                if ($cells===[null] || (count($cells)===1 && trim((string)$cells[0])==='')) continue;
                if(count($cells)!==count($header)) throw new RuntimeException('Jumlah kolom salah '.$path.' baris '.$line);
                $row=array_combine($header,$cells);
                foreach($row as $key=>$value) $row[$key]=(string)$value;
                $out[]=$row;
            }
            return $out;
        } finally {fclose($f);}
    }
    public static function byId(array $rows, string $label): array
    {
        $out=[];
        foreach($rows as $row) {
            $id=trim((string)($row['case_id']??''));
            if($id==='' || !preg_match('/^[A-Za-z0-9_-]{1,36}$/',$id)) throw new InvalidArgumentException('case_id salah pada '.$label);
            if(isset($out[$id])) throw new InvalidArgumentException('case_id duplikat '.$id.' pada '.$label);
            $out[$id]=$row;
        }
        return $out;
    }
    public static function ids(string $raw): array
    {
        $raw=trim($raw);
        // Kolom kosong berarti belum dinilai, bukan label negatif.
        if($raw==='') throw new InvalidArgumentException('Label kosong. Gunakan NONE untuk no-match yang telah dinilai.');
        if(strtoupper($raw)==='NONE') return [];
        if(!preg_match('/^[1-9][0-9]*(;[1-9][0-9]*)*$/',$raw)) throw new InvalidArgumentException('Format ID harus 12;34 atau NONE: '.$raw);
        $ids=array_map('intval',explode(';',$raw));
        $ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);
        return $ids;
    }
    public static function safeOutput(string $v): string
    {
        return preg_match('/^[=+@\-\t\r]/u',$v) ? "'".$v : $v;
    }
    public static function outputDir(string $path): string
    {
        if(!is_dir($path) && !mkdir($path,0700,true) && !is_dir($path)) throw new RuntimeException('Folder output tidak dapat dibuat');
        $absolute=realpath($path);
        $root=realpath(dirname(__DIR__));
        $prefix=rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if ($absolute===$root || str_starts_with($absolute,$prefix)) throw new InvalidArgumentException('Simpan output DI LUAR folder project/XAMPP htdocs!');
        return $absolute;
    }
    public static function dictionaryFingerprint(PDO $db): array
    {
        $stmt=$db->query("SELECT id,category_id,tolour_word,indonesian_meaning FROM dictionary WHERE status='disetujui' ORDER BY id ASC");
        $ctx=hash_init('sha256');$approved=[];$count=0;
        while($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
            $id=(int)$row['id'];$approved[$id]=true;$count++;
            $json=json_encode([
                'id'=>$id,'category_id'=>(int)$row['category_id'],
                'tolour_word'=>(string)$row['tolour_word'],
                'indonesian_meaning'=>(string)$row['indonesian_meaning']
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
            hash_update($ctx,$json."\n");
        }
        return ['sha256'=>hash_final($ctx),'count'=>$count,'id_lookup'=>$approved];
    }
    public static function summaryAgreement(array $left, array $right): array
    {
        $both=[];$match=0;$table=[[0,0],[0,0]]; // A no-match/positive by B no-match/positive
        foreach($left as $id=>$a) {
            $b=$right[$id];
            if($a===$b) $match++;
            $table[count($a)===0?0:1][count($b)===0?0:1]++;
            $both[]=$id;
        }
        $n=count($both); $po=($table[0][0]+$table[1][1])/$n;
        $pa0=($table[0][0]+$table[0][1])/$n;$pa1=1-$pa0;
        $pb0=($table[0][0]+$table[1][0])/$n;$pb1=1-$pb0;
        $pe=$pa0*$pb0+$pa1*$pb1;
        return ['exact_set_agreement'=>$match/$n,'binary_kappa'=>abs(1-$pe)<1e-12?null:($po-$pe)/(1-$pe),
            'n'=>$n,'no_match_A'=>$table[0][0]+$table[0][1],'no_match_B'=>$table[0][0]+$table[1][0]];
    }
}
