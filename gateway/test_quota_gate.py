import unittest
import quota_gate as q

class GateTest(unittest.TestCase):
    def setUp(self):
        self.now=1800000000
        self.cfg={"interface":"wgdelta","accounts":[
            {"id":1,"ip":"10.120.0.2","gb":10,"used_bytes":123,"expires":self.now+60,"active":True},
            {"id":2,"ip":"10.120.0.3","gb":20,"used_bytes":0,"expires":self.now+600,"active":True},
            {"id":3,"ip":"10.120.0.4","gb":1,"used_bytes":0,"expires":self.now,"active":True}]}
    def test_gate(self):
        r=q.render(self.cfg,self.now)
        self.assertEqual(r.count('quota name "q_1"'),2)
        self.assertEqual(r.count('quota name "q_2"'),2)
        self.assertIn(f'until {10*q.GIB-123} bytes',r)
        self.assertIn("10.120.0.2 timeout 60s",r)
        self.assertNotIn("10.120.0.4",r)
        self.assertIn('iifname "wgdelta" drop',r)
        self.assertIn('oifname "wgdelta" drop',r)
    def test_no_duplicate(self):
        self.cfg["accounts"][1]["ip"]="10.120.0.2"
        with self.assertRaises(ValueError): q.render(self.cfg,self.now)
    def test_snapshot(self):
        def counter(name,n): return {"counter":{"family":"inet","table":q.TABLE,"name":name,"bytes":n}}
        s=q.snapshot(self.cfg,{"nftables":[counter("up_1",1024),counter("down_1",4096),counter("up_2",5),counter("down_2",7)]},self.now)
        self.assertEqual(s[0]["used_bytes"],5243)
        self.assertEqual(s[1]["used_bytes"],12)
        self.assertIsNone(s[2]["used_bytes"])
    def test_invariant(self):
        s={"nftables":[{"counter":{"family":"inet","table":q.TABLE,"name":"up_1","bytes":10*q.GIB}},{"counter":{"family":"inet","table":q.TABLE,"name":"down_1","bytes":1}}]}
        with self.assertRaises(RuntimeError): q.snapshot(self.cfg,s,self.now)

if __name__=="__main__": unittest.main()
