#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import os, re, zipfile, sqlite3, logging
from pathlib import Path
from datetime import datetime

try:
    import pdfplumber
except ImportError:
    raise

try:
    import fitz
except ImportError:
    raise

logging.basicConfig(level=logging.INFO, format='%(asctime)s [%(levelname)s] %(message)s',
    handlers=[logging.FileHandler("extraction.log", encoding="utf-8"), logging.StreamHandler()])
logger = logging.getLogger(__name__)

LOCATIONS = {
    "ঢাকা": {"ঢাকা": ["সাভার","ধামরাই","কেরানীগঞ্জ","নবাবগঞ্জ","দোহার"],"গাজীপুর": ["গাজীপুর সদর","কালীগঞ্জ","কাপাসিয়া","শ্রীপুর"],"নারায়ণগঞ্জ": ["নারায়ণগঞ্জ সদর","আড়াইহাজার","বন্দর","রূপগঞ্জ","সোনারগাঁও"],"মানিকগঞ্জ": ["মানিকগঞ্জ সদর","ঘিওর","দৌলতপুর","হরিরামপুর","শিবালয়"],"মুন্সিগঞ্জ": ["মুন্সিগঞ্জ সদর","গজারিয়া","লৌহজং","শ্রীনগর","সিরাজদিখান"],"কিশোরগঞ্জ": ["কিশোরগঞ্জ সদর","ভৈরব","হোসেনপুর","ইটনা","কটিয়াদী"],"টাঙ্গাইল": ["টাঙ্গাইল সদর","বাসাইল","ভূঞাপুর","দেলদুয়ার","ঘাটাইল"],"ফরিদপুর": ["ফরিদপুর সদর","আলফাডাঙ্গা","বোয়ালমারী","চরভদ্রাসন","মধুখালী"]},
    "চট্টগ্রাম": {"চট্টগ্রাম": ["চট্টগ্রাম সদর","আনোয়ারা","বাঁশখালী","বোয়ালখালী","চন্দনাইশ"],"কক্সবাজার": ["কক্সবাজার সদর","চকরিয়া","কুতুবদিয়া","মহেশখালী","রামু","টেকনাফ"],"কুমিল্লা": ["কুমিল্লা সদর","বরুড়া","ব্রাহ্মণপাড়া","বুড়িচং","চান্দিনা"],"ফেনী": ["ফেনী সদর","ছাগলনাইয়া","দাগনভূঞা","ফুলগাজী","পরশুরাম"],"নোয়াখালী": ["নোয়াখালী সদর","বেগমগঞ্জ","চাটখিল","কোম্পানীগঞ্জ","হাতিয়া"],"রাঙামাটি": ["রাঙামাটি সদর","বাঘাইছড়ি","বরকল","কাউখালী","কাপ্তাই"],"বান্দরবান": ["বান্দরবান সদর","আলীকদম","লামা","নাইক্ষ্যংছড়ি","রোয়াংছড়ি","রুমা"]},
    "রাজশাহী": {"রাজশাহী": ["রাজশাহী সদর","বাগমারা","চারঘাট","দুর্গাপুর","গোদাগাড়ী"],"বগুড়া": ["বগুড়া সদর","আদমদীঘি","ধুনট","দুপচাঁচিয়া","গাবতলী"],"নাটোর": ["নাটোর সদর","বাগাতিপাড়া","বড়াইগ্রাম","গুরুদাসপুর","লালপুর"],"পাবনা": ["পাবনা সদর","আটঘরিয়া","বেড়া","ভাঙ্গুড়া","চাটমোহর","ঈশ্বরদী"],"নওগাঁ": ["নওগাঁ সদর","আত্রাই","বদলগাছী","ধামইরহাট","মান্দা"],"চাঁপাইনবাবগঞ্জ": ["চাঁপাইনবাবগঞ্জ সদর","ভোলাহাট","গোমস্তাপুর","নাচোল","শিবগঞ্জ"]},
    "খুলনা": {"খুলনা": ["খুলনা সদর","বটিয়াঘাটা","দাকোপ","ডুমুরিয়া","কয়রা","পাইকগাছা"],"বাগেরহাট": ["বাগেরহাট সদর","চিতলমারী","ফকিরহাট","কচুয়া","মংলা","মোরেলগঞ্জ"],"সাতক্ষীরা": ["সাতক্ষীরা সদর","আশাশুনি","দেবহাটা","কালিগঞ্জ","কলারোয়া","শ্যামনগর"],"যশোর": ["যশোর সদর","অভয়নগর","বাঘারপাড়া","চৌগাছা","ঝিকরগাছা","কেশবপুর"],"কুষ্টিয়া": ["কুষ্টিয়া সদর","ভেড়ামারা","দৌলতপুর","খোকসা","কুমারখালী"]},
    "বরিশাল": {"বরিশাল": ["বরিশাল সদর","আগৈলঝাড়া","বাবুগঞ্জ","বাকেরগঞ্জ","বানারীপাড়া","গৌরনদী"],"ভোলা": ["ভোলা সদর","বোরহানউদ্দিন","চরফ্যাশন","দৌলতখান","লালমোহন"],"পটুয়াখালী": ["পটুয়াখালী সদর","বাউফল","দশমিনা","গলাচিপা","কলাপাড়া"],"পিরোজপুর": ["পিরোজপুর সদর","ভান্ডারিয়া","কাউখালী","মঠবাড়িয়া","নাজিরপুর"]},
    "রংপুর": {"রংপুর": ["রংপুর সদর","বদরগঞ্জ","গঙ্গাচড়া","কাউনিয়া","মিঠাপুকুর","পীরগঞ্জ"],"দিনাজপুর": ["দিনাজপুর সদর","বীরগঞ্জ","বোচাগঞ্জ","চিরিরবন্দর","ঘোড়াঘাট"],"গাইবান্ধা": ["গাইবান্ধা সদর","ফুলছড়ি","গোবিন্দগঞ্জ","পলাশবাড়ী","সাদুল্লাপুর"],"কুড়িগ্রাম": ["কুড়িগ্রাম সদর","ভুরুঙ্গামারী","চিলমারী","ফুলবাড়ী","নাগেশ্বরী"],"নীলফামারী": ["নীলফামারী সদর","ডিমলা","ডোমার","জলঢাকা","সৈয়দপুর"],"লালমনিরহাট": ["লালমনিরহাট সদর","আদিতমারী","হাতীবান্ধা","কালীগঞ্জ","পাটগ্রাম"],"পঞ্চগড়": ["পঞ্চগড় সদর","আটোয়ারী","বোদা","দেবীগঞ্জ","তেঁতুলিয়া"],"ঠাকুরগাঁও": ["ঠাকুরগাঁও সদর","বালিয়াডাঙ্গী","হরিপুর","পীরগঞ্জ","রাণীশংকৈল"]},
    "ময়মনসিংহ": {"ময়মনসিংহ": ["ময়মনসিংহ সদর","ভালুকা","ধোবাউড়া","ফুলবাড়িয়া","গফরগাঁও"],"নেত্রকোণা": ["নেত্রকোণা সদর","আটপাড়া","বারহাট্টা","দুর্গাপুর","খালিয়াজুরী"],"জামালপুর": ["জামালপুর সদর","বকশীগঞ্জ","দেওয়ানগঞ্জ","ইসলামপুর","মাদারগঞ্জ"],"শেরপুর": ["শেরপুর সদর","ঝিনাইগাতী","নালিতাবাড়ী","নকলা","শ্রীবরদী"]},
    "সিলেট": {"সিলেট": ["সিলেট সদর","বালাগঞ্জ","বিয়ানীবাজার","বিশ্বনাথ","ফেঞ্চুগঞ্জ","গোয়াইনঘাট"],"মৌলভীবাজার": ["মৌলভীবাজার সদর","বড়লেখা","জুড়ী","কমলগঞ্জ","কুলাউড়া","শ্রীমঙ্গল"],"হবিগঞ্জ": ["হবিগঞ্জ সদর","আজমিরীগঞ্জ","বাহুবল","বানিয়াচং","চুনারুঘাট","নবীগঞ্জ"],"সুনামগঞ্জ": ["সুনামগঞ্জ সদর","ছাতক","দিরাই","ধর্মপাশা","দোয়ারাবাজার","জগন্নাথপুর"]},
}

class PDFExtractor:
    def __init__(self):
        self.stats = {"success": 0, "failed": 0, "total_pages": 0}

    def extract(self, pdf_path):
        try:
            doc = fitz.open(pdf_path)
            text = "\n".join(page.get_text("text", sort=True) for page in doc)
            self.stats["total_pages"] += len(doc)
            doc.close()
            if text.strip():
                return self.clean(text)
        except: pass
        try:
            with pdfplumber.open(pdf_path) as pdf:
                text = "\n".join(p.extract_text(x_tolerance=3, y_tolerance=3) or "" for p in pdf.pages)
            if text.strip():
                return self.clean(text)
        except: pass
        return ""

    def clean(self, text):
        lines, out = text.split('\n'), []
        for line in lines:
            line = re.sub(r'[ \t]+', ' ', line.strip())
            if line:
                out.append(line)
            elif out and out[-1] != '':
                out.append('')
        while out and out[-1] == '': out.pop()
        return '\n'.join(out)

class LocationDetector:
    def __init__(self):
        self.upa_map, self.dis_map = {}, {}
        for div, districts in LOCATIONS.items():
            for dis, upas in districts.items():
                self.dis_map[dis] = div
                for upa in upas:
                    self.upa_map[upa] = (dis, div)

    def detect(self, filename, text):
        search = Path(filename).stem.replace('_',' ').replace('-',' ') + "\n" + text[:500] + text[-200:]
        r = {"division": None, "district": None, "upazila": None}
        for upa, (dis, div) in self.upa_map.items():
            if upa in search:
                r = {"division": div, "district": dis, "upazila": upa}
                break
        if not r["district"]:
            for dis, div in self.dis_map.items():
                if dis in search:
                    r["division"] = div
                    r["district"] = dis
                    break
        if not r["division"]:
            for div in LOCATIONS:
                if div in search:
                    r["division"] = div
                    break
        return r

class DB:
    def __init__(self, path):
        self.conn = sqlite3.connect(path)
        self.conn.execute("PRAGMA journal_mode=WAL")
        self._create()

    def _create(self):
        self.conn.executescript("""
        CREATE TABLE IF NOT EXISTS documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            zip_file TEXT, pdf_filename TEXT,
            division TEXT, district TEXT, upazila TEXT,
            content TEXT, page_count INTEGER DEFAULT 0,
            word_count INTEGER DEFAULT 0, char_count INTEGER DEFAULT 0,
            extraction_date TEXT, status TEXT DEFAULT 'processed',
            UNIQUE(zip_file, pdf_filename)
        );
        CREATE VIRTUAL TABLE IF NOT EXISTS documents_fts USING fts5(
            id UNINDEXED, division, district, upazila,
            pdf_filename, content,
            content='documents', content_rowid='id', tokenize='unicode61'
        );
        CREATE TABLE IF NOT EXISTS extraction_stats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            zip_file TEXT, total_pdfs INTEGER, successful INTEGER,
            failed INTEGER, processed_at TEXT
        );
        CREATE TABLE IF NOT EXISTS error_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            zip_file TEXT, pdf_filename TEXT, error_message TEXT, logged_at TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_div ON documents(division);
        CREATE INDEX IF NOT EXISTS idx_dis ON documents(district);
        CREATE INDEX IF NOT EXISTS idx_upa ON documents(upazila);
        CREATE INDEX IF NOT EXISTS idx_div_dis_upa ON documents(division,district,upazila);
        """)
        self.conn.commit()

    def insert(self, d):
        cur = self.conn.execute("""
            INSERT OR REPLACE INTO documents
            (zip_file,pdf_filename,division,district,upazila,content,
             page_count,word_count,char_count,extraction_date,status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        """, (d["zip_file"],d["pdf_filename"],d.get("division"),d.get("district"),
              d.get("upazila"),d["content"],d.get("page_count",0),
              d.get("word_count",0),d.get("char_count",0),
              d.get("extraction_date"),d.get("status","processed")))
        doc_id = cur.lastrowid
        self.conn.execute("""
            INSERT OR REPLACE INTO documents_fts
            (id,division,district,upazila,pdf_filename,content)
            VALUES (?,?,?,?,?,?)
        """, (doc_id,d.get("division",""),d.get("district",""),
              d.get("upazila",""),d["pdf_filename"],d["content"]))
        self.conn.commit()
        return doc_id

    def log_error(self, zf, pf, err):
        self.conn.execute(
            "INSERT INTO error_log(zip_file,pdf_filename,error_message,logged_at) VALUES(?,?,?,?)",
            (zf, pf, err, datetime.now().isoformat()))
        self.conn.commit()

    def save_stats(self, zf, total, ok, fail):
        self.conn.execute(
            "INSERT INTO extraction_stats(zip_file,total_pdfs,successful,failed,processed_at) VALUES(?,?,?,?,?)",
            (zf, total, ok, fail, datetime.now().isoformat()))
        self.conn.commit()

    def rebuild_fts(self):
        self.conn.execute("INSERT INTO documents_fts(documents_fts) VALUES('rebuild')")
        self.conn.commit()

    def close(self):
        self.conn.close()

class Processor:
    def __init__(self, db_path="pdf_database.db"):
        self.ex = PDFExtractor()
        self.det = LocationDetector()
        self.db = DB(db_path)
        self.tmp = Path("temp_pdfs")
        self.tmp.mkdir(exist_ok=True)

    def words(self, text):
        return len(re.findall(r'[\u0980-\u09FF]+|[a-zA-Z]+', text))

    def process_pdf(self, path, zf, name):
        text = self.ex.extract(path)
        if not text or len(text.strip()) < 10:
            self.db.log_error(zf, name, "No text extracted")
            return False
        loc = self.det.detect(name, text)
        try:
            doc = fitz.open(path); pages = len(doc); doc.close()
        except: pages = 0
        doc_id = self.db.insert({
            "zip_file": zf, "pdf_filename": name,
            "division": loc.get("division"), "district": loc.get("district"),
            "upazila": loc.get("upazila"), "content": text,
            "page_count": pages, "word_count": self.words(text),
            "char_count": len(text), "extraction_date": datetime.now().isoformat()
        })
        logger.info(f"✓ ID:{doc_id} | {loc.get('division','?')} › {loc.get('district','?')} › {loc.get('upazila','?')}")
        return True

    def process_zip(self, zip_path):
        zf = Path(zip_path).name
        stats = {"total": 0, "success": 0, "failed": 0}
        with zipfile.ZipFile(zip_path, 'r') as z:
            pdfs = [f for f in z.namelist() if f.lower().endswith('.pdf') and not f.startswith('__MACOSX')]
            stats["total"] = len(pdfs)
            logger.info(f"ZIP: {zf} | PDF: {len(pdfs)}")
            for i, pname in enumerate(pdfs, 1):
                tmp = self.tmp / f"tmp_{i}_{Path(pname).name}"
                try:
                    with z.open(pname) as src, open(tmp,'wb') as dst: dst.write(src.read())
                    if self.process_pdf(str(tmp), zf, Path(pname).name): stats["success"] += 1
                    else: stats["failed"] += 1
                except Exception as e:
                    self.db.log_error(zf, pname, str(e)); stats["failed"] += 1
                finally:
                    if tmp.exists(): tmp.unlink()
        self.db.save_stats(zf, stats["total"], stats["success"], stats["failed"])
        logger.info(f"সম্পন্ন: {stats}")
        return stats

    def process_folder(self, folder):
        zips = list(Path(folder).glob("*.zip"))
        for i, z in enumerate(zips, 1):
            logger.info(f"[{i}/{len(zips)}] {z.name}")
            self.process_zip(str(z))
        self.db.rebuild_fts()

    def cleanup(self):
        import shutil
        if self.tmp.exists(): shutil.rmtree(self.tmp)
        self.db.close()

if __name__ == "__main__":
    import sys
    if len(sys.argv) < 2:
        print("ব্যবহার: python pdf_extractor.py <zip_file_or_folder> [db_path]")
        sys.exit(1)
    db = sys.argv[2] if len(sys.argv) > 2 else "pdf_database.db"
    p = Processor(db)
    try:
        path = Path(sys.argv[1])
        if path.is_file(): p.process_zip(str(path))
        elif path.is_dir(): p.process_folder(str(path))
    finally:
        p.cleanup()
        print(f"✓ ডেটাবেজ সেভ: {db}")
