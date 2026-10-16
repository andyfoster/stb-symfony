<?php

namespace App\Enum;

enum Category: string
{
    case Textbooks = 'textbooks';
    case CourseGear = 'course_gear';
    case Electronics = 'electronics';
    case Furniture = 'furniture';
    case FlatAndKitchen = 'flat_and_kitchen';
    case BikesAndTransport = 'bikes_and_transport';
    case Clothing = 'clothing';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Textbooks => 'Textbooks',
            self::CourseGear => 'Course gear',
            self::Electronics => 'Electronics',
            self::Furniture => 'Furniture',
            self::FlatAndKitchen => 'Flat & kitchen',
            self::BikesAndTransport => 'Bikes & transport',
            self::Clothing => 'Clothing',
            self::Other => 'Other',
        };
    }
}
