package main

import (
	"testing"
)

func TestBloomFilter_Hash1(t *testing.T) {
	var bf = BloomFilter{}
	bf.filter_len = 32
	var result = bf.Hash1("0123456789")

	if bf.filter_len != 32 {
		t.Error("Error")
	}

	if result != 13 {
		t.Error("Error")
	}

}

func TestBloomFilter_Hash2(t *testing.T) {
	var bf = BloomFilter{}
	bf.filter_len = 32
	var result = bf.Hash2("0123456789")

	if bf.filter_len != 32 {
		t.Error("Error")
	}

	if result != 5 {
		t.Error("Error")
	}
}

func TestBloomFilter_Add(t *testing.T) {
	var bf = BloomFilter{}
	bf.filter_len = 32

	bf.Add("0123456789")
	if bf.filter_len != 32 {
		t.Error("Error")
	}

	bf.Add("1234567890")
	if bf.filter_len != 32 {
		t.Error("Error")
	}

	bf.Add("2345678901")
	if bf.filter_len != 32 {
		t.Error("Error")
	}

	bf.Add("3456789012")
	if bf.filter_len != 32 {
		t.Error("Error")
	}

	bf.Add("4567890123")
	if bf.filter_len != 32 {
		t.Error("Error")
	}

	var result = bf.IsValue("1678901234")
	if result != false {
		t.Error("Error")
	}
	bf.Add("5678901234")
	result = bf.IsValue("5678901234")
	if result != true {
		t.Error("Error")
	}

}