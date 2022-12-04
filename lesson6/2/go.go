package main

import (
	"os"
)

// битовый массив длиной f_len ...
type BloomFilter struct {
	filter_len int
	bits       uint32
}

// хэш-функции 17
func (bf *BloomFilter) Hash1(s string) int {
	sum := 0
	for _, char := range s {
		code := int(char)
		sum = (sum*17 + code) % bf.filter_len
	}
	return sum
}

// 223
func (bf *BloomFilter) Hash2(s string) int {
	sum := 0
	for _, char := range s {
		code := int(char)
		sum = (sum*223 + code) % bf.filter_len
	}
	return sum
}

// добавляем строку s в фильтр
func (bf *BloomFilter) Add(s string) {
	var hash1 = uint32(bf.Hash1(s))
	var hash2 = uint32(bf.Hash2(s))
	var index = (hash1) % uint32(bf.filter_len)
	bf.bits |= 1 << uint(index&0x3f)

	index = (hash2) % uint32(bf.filter_len)
	bf.bits |= 1 << uint(index&0x3f)

}

// проверка, имеется ли строка s в фильтре
func (bf *BloomFilter) IsValue(s string) bool {
	var hash1 = uint32(bf.Hash1(s))
	var hash2 = uint32(bf.Hash2(s))
	var index1 = (hash1) % uint32(bf.filter_len)
	var index2 = (hash2) % uint32(bf.filter_len)
	var answer2 = (bf.bits >> uint(index2&0x3f)) & 1
	if answer2 == 0 {
		return false
	}
	var answer1 = (bf.bits >> uint(index1&0x3f)) & 1
	if answer1 == 0 {
		return false
	}
	return true

}