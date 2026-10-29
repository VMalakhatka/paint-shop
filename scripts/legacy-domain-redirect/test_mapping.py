import copy
import unittest
from build_mapping import build


class MappingTest(unittest.TestCase):
    def setUp(self):
        self.data = {"public": {
            "products": [{"old_id": 1, "sku": "P-1", "target_id": 10,
                          "target_url": "https://kreul.com.ua/p/paint/"}],
            "unmatched_products": [],
            "categories": [{"category_id": 1, "parent_id": 0, "status": "1", "name": "КАТАЛОГ"},
                           {"category_id": 2, "parent_id": 1, "status": "1", "name": "Paint"}],
            "product_categories": [{"product_id": 1, "category_id": 2}],
            "aliases": [{"keyword": "paint", "query": "category_id=2"}], "information": [],
            "woo_categories": [{"id": 20, "parent": 0, "name": "Paint", "url": "https://kreul.com.ua/category/paint/"},
                               {"id": 21, "parent": 0, "name": "Paint", "url": "https://kreul.com.ua/category/paint-2/"}],
            "woo_relationships": [{"product_id": 10, "category_id": 21}]}}

    def test_duplicate_resolved_by_contents(self):
        mapping, report = build(self.data)
        self.assertEqual(mapping["categories"]["2"], "https://kreul.com.ua/category/paint-2/")
        self.assertEqual(report["categories_unresolved"], [])

    def test_ambiguous_contents_are_not_guessed(self):
        self.data["public"]["woo_relationships"].append({"product_id": 10, "category_id": 20})
        self.assertNotIn("2", build(self.data)[0]["categories"])

    def test_unique_name_with_wrong_contents_is_not_enough(self):
        self.data["public"]["woo_categories"].pop()
        self.data["public"]["woo_relationships"] = []
        self.assertNotIn("2", build(self.data)[0]["categories"])

    def test_duplicate_alias_omitted(self):
        self.data["public"]["aliases"].append({"keyword": "paint", "query": "information_id=1"})
        self.assertNotIn("paint", build(self.data)[0]["aliases"])

    def test_customer_dataset_rejected(self):
        self.data["public"]["customers"] = []
        with self.assertRaises(ValueError):
            build(self.data)

    def test_external_target_rejected(self):
        self.data["public"]["products"][0]["target_url"] = "https://attacker.test/"
        with self.assertRaises(ValueError):
            build(self.data)

    def test_duplicate_sku_rejected(self):
        row = copy.deepcopy(self.data["public"]["products"][0])
        row["old_id"] = 2
        self.data["public"]["products"].append(row)
        with self.assertRaises(ValueError):
            build(self.data)

    def test_cycle_rejected(self):
        self.data["public"]["woo_categories"][0]["parent"] = 20
        with self.assertRaises(ValueError):
            build(self.data)


if __name__ == "__main__":
    unittest.main()
